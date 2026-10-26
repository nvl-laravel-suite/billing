<?php

declare(strict_types=1);
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Services\BillingReconciler;

it('repairs stale local subscription state from the current Stripe object', function (): void {
    billingNativeRun(function () {
        $tenant = billingNativeOwner(7);
        $account = BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_test']);
        $subscription = BillingSubscription::factory()->forAccount($account)->create(['type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'past_due', 'stripe_price' => 'price_old']);
        app()->instance(SubscriptionReader::class, new class implements SubscriptionReader
        {
            public function forCustomer(string $customerId): array
            {
                return [['id' => 'sub_test', 'customer' => $customerId, 'status' => 'active', 'created' => time(), 'metadata' => ['type' => 'default'], 'trial_end' => null, 'cancel_at_period_end' => false, 'items' => ['data' => [['id' => 'si_test', 'price' => ['id' => 'price_pro_month', 'product' => 'prod_pro'], 'quantity' => 1]]]]];
            }
        });
        app(BillingReconciler::class)->reconcile($tenant);
        expect($subscription->fresh()->stripe_status)->toBe('active')->and($subscription->fresh()->stripe_price)->toBe('price_pro_month')->and($subscription->fresh()->items()->first()->stripe_price)->toBe('price_pro_month');
    });
});
it('ends a local subscription missing from a complete Stripe listing', function (): void {
    billingNativeRun(function () {
        $tenant = billingNativeOwner(7);
        $account = BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_test']);
        $subscription = BillingSubscription::factory()->forAccount($account)->create(['type' => 'default', 'stripe_id' => 'sub_orphan', 'stripe_status' => 'active', 'stripe_price' => 'price_pro_month']);
        app()->instance(SubscriptionReader::class, new class implements SubscriptionReader
        {
            public function forCustomer(string $customerId): array
            {
                return [];
            }
        });
        app(BillingReconciler::class)->reconcile($tenant);
        expect($subscription->fresh()->stripe_status)->toBe('canceled')->and($subscription->fresh()->ends_at)->not->toBeNull();
    });
});
