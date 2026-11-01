<?php

declare(strict_types=1);
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Ownership\Tests\Fixtures\Company;

it('owns one billable account and Cashier subscription per tenant', function (): void {
    billingNativeRun(function () {
        $tenant = Company::query()->findOrFail(7);
        $account = BillingAccount::query()->create(['email' => 'billing@example.test']);
        expect(Str::isUuid($account->id))->toBeTrue()->and(Schema::hasTable('nvl_billing_accounts'))->toBeTrue()->and(Schema::hasTable('nvl_billing_subscriptions'))->toBeTrue()->and(Schema::hasTable('nvl_billing_subscription_items'))->toBeTrue();
        $subscription = BillingSubscription::factory()->forAccount($account)->create(['type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'trialing', 'stripe_price' => 'price_pro_month']);
        expect($subscription->owner->is($account))->toBeTrue()->and($subscription->getTable())->toBe('nvl_billing_subscriptions');
        expect(fn () => BillingAccount::query()->create(['email' => 'another@example.test']))->toThrow(UniqueConstraintViolationException::class);
    });
});

it('hides provider and checkout recovery capabilities from default model serialization', function (): void {
    $sensitive = [
        'stripe_id' => 'cus_private_account',
        'pending_checkout_attempt_id' => (string) Str::uuid(),
        'pending_checkout_request_hash' => hash('sha256', 'private checkout request'),
        'pending_checkout_session_id' => 'cs_private_session',
        'pending_checkout_url' => 'https://checkout.stripe.com/private-capability',
    ];
    $account = BillingAccount::factory()->make($sensitive);

    expect($account->toArray())->not->toHaveKeys(array_keys($sensitive))
        ->and(json_decode($account->toJson(), true, flags: JSON_THROW_ON_ERROR))->not->toHaveKeys(array_keys($sensitive));
    foreach ($sensitive as $attribute => $value) {
        expect($account->getAttribute($attribute))->toBe($value);
    }
});
