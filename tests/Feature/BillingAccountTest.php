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
