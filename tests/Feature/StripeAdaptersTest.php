<?php

declare(strict_types=1);
use Carbon\CarbonImmutable;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Services\StripeBillingGateway;
use Nvl\Billing\Services\StripeSubscriptionReader;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

afterEach(function (): void {
    ApiRequestor::setHttpClient(null);
});
/** @param list<array<string, mixed>> $responses */
function billingStripeHttp(array $responses, array &$requests): void
{
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->times(count($responses))->andReturnUsing(function (string $method, string $url, array $headers, array $parameters) use (&$requests, &$responses): array {
        $requests[] = compact('method', 'url', 'headers', 'parameters');

        return [json_encode(array_shift($responses), JSON_THROW_ON_ERROR), 200, ['Request-Id' => 'req_billing_test']];
    });
    ApiRequestor::setHttpClient($http);
}
it('creates a Stripe customer and an idempotent hosted subscription checkout', function (): void {
    billingNativeRun(function () {
        $account = BillingAccount::query()->forceCreate(['name' => 'Acme Billing', 'email' => 'billing@example.test']);
        $requests = [];
        billingStripeHttp([['object' => 'customer', 'id' => 'cus_created'], ['object' => 'checkout.session', 'id' => 'cs_created', 'url' => 'https://checkout.stripe.test/new']], $requests);
        $session = app(StripeBillingGateway::class)->checkout($account, 'price_pro', 0, 'https://app.test/success', 'https://app.test/cancel', CarbonImmutable::now()->addHours(2), 'attempt-1');
        expect($account->fresh()->stripe_id)->toBe('cus_created')->and($session['id'])->toBe('cs_created')->and($session['url'])->toBe('https://checkout.stripe.test/new')->and($requests[0]['url'])->toEndWith('/v1/customers')->and($requests[0]['parameters']['name'])->toBe('Acme Billing')->and($requests[0]['parameters']['email'])->toBe('billing@example.test')->and($requests[1]['url'])->toEndWith('/v1/checkout/sessions')->and($requests[1]['parameters']['customer'])->toBe('cus_created')->and($requests[1]['parameters']['client_reference_id'])->toBe($account->id)->and($requests[1]['parameters']['line_items'])->toBe([['price' => 'price_pro', 'quantity' => 1]])->and($requests[1]['parameters']['subscription_data']['metadata'])->toBe(['type' => 'default', 'billing_account_id' => $account->id])->and($requests[1]['parameters']['payment_method_collection'])->toBe('always');
    });
});
it('configures a cardless trial and delegates portal and contact updates to Stripe', function (): void {
    billingNativeRun(function () {
        config()->set('nvl-billing.trial.require_payment_method', false);
        $account = BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_existing']);
        $requests = [];
        billingStripeHttp([['object' => 'checkout.session', 'id' => 'cs_trial', 'url' => 'https://checkout.stripe.test/trial'], ['object' => 'billing_portal.session', 'id' => 'bps_test', 'url' => 'https://billing.stripe.test/portal'], ['object' => 'customer', 'id' => 'cus_existing']], $requests);
        $gateway = app(StripeBillingGateway::class);
        $session = $gateway->checkout($account, 'price_pro', 14, 'https://app.test/success', 'https://app.test/cancel', CarbonImmutable::now()->addHours(2), 'attempt-2');
        $portal = $gateway->portal($account, 'https://app.test/billing');
        $gateway->updateCustomer($account, 'Acme', 'new@example.test');
        expect($session['id'])->toBe('cs_trial')->and($portal)->toBe('https://billing.stripe.test/portal')->and($requests[0]['parameters']['payment_method_collection'])->toBe('if_required')->and($requests[0]['parameters']['subscription_data']['trial_settings']['end_behavior']['missing_payment_method'])->toBe('cancel')->and($requests[0]['parameters']['subscription_data']['trial_end'])->toBeInt()->and($requests[1]['parameters'])->toBe(['customer' => 'cus_existing', 'return_url' => 'https://app.test/billing'])->and($requests[2]['parameters'])->toBe(['name' => 'Acme', 'email' => 'new@example.test']);
    });
});
it('pages Stripe subscriptions into reconciliation payloads', function (): void {
    billingNativeRun(function () {
        $requests = [];
        billingStripeHttp([
            ['object' => 'list', 'data' => [['object' => 'subscription', 'id' => 'sub_first', 'customer' => 'cus_existing', 'status' => 'active']], 'has_more' => true, 'url' => '/v1/subscriptions'],
            ['object' => 'list', 'data' => [['object' => 'subscription', 'id' => 'sub_second', 'customer' => 'cus_existing', 'status' => 'canceled']], 'has_more' => false, 'url' => '/v1/subscriptions'],
        ], $requests);
        $subscriptions = app(StripeSubscriptionReader::class)->forCustomer('cus_existing');
        expect(array_column($subscriptions, 'id'))->toBe(['sub_first', 'sub_second'])
            ->and($requests[0]['url'])->toStartWith('https://api.stripe.com/v1/subscriptions')
            ->and($requests[0]['parameters'])->toBe(['customer' => 'cus_existing', 'status' => 'all', 'limit' => 100])
            ->and($requests[1]['parameters'])->toBe(['customer' => 'cus_existing', 'status' => 'all', 'limit' => 100, 'starting_after' => 'sub_first']);
    });
});

it('reads complete Stripe subscription items before returning reconciliation payloads', function (): void {
    billingNativeRun(function (): void {
        $first = ['object' => 'subscription_item', 'id' => 'si_first', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1];
        $second = ['object' => 'subscription_item', 'id' => 'si_second', 'price' => ['id' => 'price_extra', 'product' => 'prod_extra'], 'quantity' => 2];
        $third = ['object' => 'subscription_item', 'id' => 'si_third', 'price' => ['id' => 'price_other', 'product' => 'prod_other'], 'quantity' => 3];
        $requests = [];
        billingStripeHttp([
            [
                'object' => 'list',
                'data' => [[
                    'object' => 'subscription',
                    'id' => 'sub_existing',
                    'customer' => 'cus_existing',
                    'status' => 'active',
                    'items' => ['object' => 'list', 'data' => [$first], 'has_more' => true, 'url' => '/v1/subscription_items?subscription=sub_existing'],
                ]],
                'has_more' => false,
                'url' => '/v1/subscriptions',
            ],
            ['object' => 'list', 'data' => [$first, $second], 'has_more' => true, 'url' => '/v1/subscription_items'],
            ['object' => 'list', 'data' => [$third], 'has_more' => false, 'url' => '/v1/subscription_items'],
        ], $requests);

        $subscriptions = app(StripeSubscriptionReader::class)->forCustomer('cus_existing');

        expect($subscriptions)->toHaveCount(1)
            ->and($subscriptions[0]['items']['data'])->toEqual([$first, $second, $third])
            ->and($subscriptions[0]['items']['has_more'])->toBeFalse()
            ->and($requests[1]['url'])->toStartWith('https://api.stripe.com/v1/subscription_items')
            ->and($requests[1]['parameters'])->toBe(['subscription' => 'sub_existing', 'limit' => 100])
            ->and($requests[2]['parameters'])->toBe(['subscription' => 'sub_existing', 'limit' => 100, 'starting_after' => 'si_second']);
    });
});
