<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Cashier;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Payments\Providers\PaymentsServiceProvider;

beforeEach(function (): void {
    if (! class_exists(PaymentsServiceProvider::class)) {
        return;
    }
    config(['nvl-payments.enabled' => true]);
    app()->register(PaymentsServiceProvider::class, true);
    Route::getRoutes()->refreshNameLookups();
    expect(Route::getRoutes()->getByName('nvl.payments.webhook'))->not->toBeNull();
});

it('keeps both webhook routes and Billing Cashier models when Payments loads', function (): void {
    if (! class_exists(PaymentsServiceProvider::class)) {
        $this->markTestSkipped('Payments is not installed.');
    }

    expect(Route::getRoutes()->getByName('nvl.billing.webhook'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('nvl.payments.webhook'))->not->toBeNull()
        ->and(Cashier::$customerModel)->toBe(BillingAccount::class)
        ->and(Cashier::$subscriptionModel)->toBe(BillingSubscription::class)
        ->and(Cashier::$subscriptionItemModel)->toBe(BillingSubscriptionItem::class);
});

it('requires a signed webhook and applies each Stripe event once', function (): void {
    $account = BillingAccount::query()->forceCreate([
        'tenant_id' => '1bc8245c-81fe-4ffb-b90a-99088939ed5e',
        'email' => 'billing@example.test',
        'stripe_id' => 'cus_billing_test',
        'pending_checkout_attempt_id' => 'a3922877-192b-436e-a562-e97b3a719152',
        'pending_checkout_price' => 'price_pro_month',
    ]);
    $event = [
        'id' => 'evt_billing_test',
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_billing_test',
            'customer' => 'cus_billing_test',
            'status' => 'trialing',
            'trial_end' => now()->addDays(14)->timestamp,
            'metadata' => ['type' => 'default'],
            'items' => ['data' => [[
                'id' => 'si_billing_test',
                'price' => ['id' => 'price_pro_month', 'product' => 'prod_pro'],
                'quantity' => 1,
            ]]],
        ]],
    ];
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}"];

    $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertForbidden();
    $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], $server, $payload)->assertSuccessful();
    $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], $server, $payload)->assertSuccessful();

    expect($account->fresh()->trial_consumed_at)->not->toBeNull()
        ->and($account->fresh()->pending_checkout_attempt_id)->toBeNull()
        ->and($account->subscriptions()->count())->toBe(1)
        ->and(DB::table(BillingTables::WebhookEvents)->count())->toBe(1);
});

it('rejects malformed signed billing envelopes before recording or applying them', function (string $payload): void {
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');

    $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}",
    ], $payload)->assertBadRequest();

    expect(DB::table(BillingTables::WebhookEvents)->count())->toBe(0);
})->with([
    '{',
    'null',
    '{"id":"","type":"customer.updated","data":{"object":{"id":"cus_test"}}}',
    '{"id":"not_an_event","type":"customer.updated","data":{"object":{"id":"cus_test"}}}',
    '{"id":"evt_bad","type":"customer.updated","data":{"object":null}}',
    '{"id":"evt_bad","type":"customer.updated","data":{"object":{"id":[]}}}',
    '{"id":"evt_bad","type":"","data":{"object":{"id":"cus_test"}}}',
]);

it('records a completed trial from a later active subscription event', function (): void {
    $account = BillingAccount::query()->forceCreate([
        'tenant_id' => '1bc8245c-81fe-4ffb-b90a-99088939ed5e',
        'email' => 'billing@example.test',
        'stripe_id' => 'cus_completed_trial',
    ]);
    $event = [
        'id' => 'evt_completed_trial',
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_completed_trial',
            'customer' => 'cus_completed_trial',
            'status' => 'active',
            'trial_start' => now()->subDays(14)->timestamp,
            'trial_end' => now()->subMinute()->timestamp,
            'metadata' => ['type' => 'default'],
            'items' => ['data' => [[
                'id' => 'si_completed_trial',
                'price' => ['id' => 'price_pro_month', 'product' => 'prod_pro'],
                'quantity' => 1,
            ]]],
        ]],
    ];
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');

    $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}",
    ], $payload)->assertSuccessful();

    expect($account->fresh()->trial_consumed_at)->not->toBeNull();
});

it('syncs a scheduled cancellation from the signed payload without a Stripe request', function (): void {
    $account = BillingAccount::query()->forceCreate([
        'tenant_id' => '1bc8245c-81fe-4ffb-b90a-99088939ed5e',
        'email' => 'billing@example.test',
        'stripe_id' => 'cus_cancel_test',
    ]);
    $periodEnd = now()->addDays(12)->timestamp;
    $event = [
        'id' => 'evt_cancel_test',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_cancel_test',
            'customer' => 'cus_cancel_test',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'metadata' => ['type' => 'default'],
            'items' => ['data' => [[
                'id' => 'si_cancel_test',
                'price' => ['id' => 'price_pro_month', 'product' => 'prod_pro'],
                'quantity' => 1,
                'current_period_end' => $periodEnd,
            ]]],
        ]],
    ];
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');

    $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}",
    ], $payload)->assertSuccessful();

    expect($account->subscriptions()->first()->ends_at?->timestamp)->toBe($periodEnd);
});

it('synchronizes an explicit billing contact changed through the Stripe portal', function (): void {
    $account = BillingAccount::query()->forceCreate([
        'tenant_id' => '1bc8245c-81fe-4ffb-b90a-99088939ed5e',
        'email' => 'old@example.test',
        'stripe_id' => 'cus_contact_test',
    ]);
    $payload = json_encode([
        'id' => 'evt_contact_test',
        'type' => 'customer.updated',
        'data' => ['object' => [
            'id' => 'cus_contact_test',
            'name' => 'Acme Billing',
            'email' => 'new@example.test',
        ]],
    ], JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');

    $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}",
    ], $payload)->assertSuccessful();

    expect($account->fresh()->name)->toBe('Acme Billing')
        ->and($account->fresh()->email)->toBe('new@example.test');
});

it('revokes subscription access when Stripe deletes the billing customer', function (): void {
    $account = BillingAccount::query()->forceCreate([
        'tenant_id' => '1bc8245c-81fe-4ffb-b90a-99088939ed5e',
        'email' => 'billing@example.test',
        'stripe_id' => 'cus_deleted',
    ]);
    $subscription = $account->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_deleted_customer',
        'stripe_status' => 'active',
    ]);
    $payload = json_encode([
        'id' => 'evt_customer_deleted',
        'type' => 'customer.deleted',
        'data' => ['object' => ['id' => 'cus_deleted']],
    ], JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');

    $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}",
    ], $payload)->assertNoContent();

    expect($account->fresh()->stripe_id)->toBeNull()
        ->and($subscription->fresh()->stripe_status)->toBe('canceled')
        ->and($subscription->fresh()->ends_at)->not->toBeNull();
});
