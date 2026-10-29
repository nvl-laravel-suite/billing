<?php

declare(strict_types=1);
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Billing\Services\BillingAccess;
use Nvl\Billing\Services\BillingReconciler;

it('uses the configured subscription type when provider metadata omits it', function (): void {
    billingNativeRun(function (): void {
        config()->set('nvl-billing.subscription_type', 'workspace');
        BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_type']);
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldReceive('forCustomer')->once()->andReturn([
            ['id' => 'sub_type', 'customer' => 'cus_type', 'status' => 'active', 'items' => ['data' => [['id' => 'si_type', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1]]]],
        ]);
        app()->instance(SubscriptionReader::class, $reader);
        app(BillingReconciler::class)->reconcile(billingNativeOwner(7));
        expect(BillingSubscription::query()->sole()->type)->toBe('workspace')
            ->and(app(BillingAccess::class)->forOwner(billingNativeOwner(7))['plan'])->toBe('pro');
    });
});

it('rejects a stale subscription listing when local billing changes during the provider read', function (string $change): void {
    billingNativeRun(function () use ($change): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_race']);
        $subscription = BillingSubscription::factory()->forAccount($account)->create(['type' => 'default', 'stripe_id' => 'sub_race', 'stripe_status' => 'active']);
        $item = BillingSubscriptionItem::factory()->forSubscription($subscription)->create(['stripe_id' => 'si_race', 'stripe_product' => 'prod_pro', 'stripe_price' => 'price_pro', 'quantity' => 1]);
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldReceive('forCustomer')->once()->andReturnUsing(function () use ($change, $account, $subscription, $item): array {
            match ($change) {
                'account' => $account->update(['name' => 'Concurrent contact']),
                'subscription' => $subscription->update(['stripe_status' => 'paused']),
                'item' => $item->update(['quantity' => 2]),
                'insert' => BillingSubscription::factory()->forAccount($account)->create(['stripe_id' => 'sub_concurrent', 'stripe_status' => 'active']),
            };

            return [['id' => 'sub_race', 'customer' => 'cus_race', 'status' => 'active', 'items' => ['data' => [['id' => 'si_race', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1]]]]];
        });
        app()->instance(SubscriptionReader::class, $reader);
        expect(fn () => app(BillingReconciler::class)->reconcile(billingNativeOwner(7)))
            ->toThrow(BillingException::class, 'changed during reconciliation');
    });
})->with(['account', 'subscription', 'item', 'insert']);

it('rejects reconciliation inside an ambient transaction before calling the provider', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_transaction']);
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldNotReceive('forCustomer');
        app()->instance(SubscriptionReader::class, $reader);
        $connection = $account->getConnection();
        $connection->beginTransaction();
        try {
            expect(fn () => app(BillingReconciler::class)->reconcile(billingNativeOwner(7)))
                ->toThrow(DomainException::class, 'outside a database transaction');
        } finally {
            $connection->rollBack();
        }
    });
});

it('does not contact the provider for disabled billing or a deleted customer', function (bool $disabled): void {
    billingNativeRun(function () use ($disabled): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_inactive', 'provider_deleted_at' => $disabled ? null : now()]);
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldNotReceive('forCustomer');
        app()->instance(SubscriptionReader::class, $reader);
        if ($disabled) {
            config()->set('nvl-billing.enabled', false);
            expect(fn () => app(BillingReconciler::class)->reconcile(billingNativeOwner(7)))
                ->toThrow(BillingException::class, 'Billing is disabled');
        } else {
            app(BillingReconciler::class)->reconcile(billingNativeOwner(7));
        }
        expect($account->fresh()->stripe_id)->toBe('cus_inactive');
    });
})->with([true, false]);

it('rejects duplicate subscription identities without partially applying the listing', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_duplicate']);
        $subscription = BillingSubscription::factory()->forAccount($account)->create(['stripe_id' => 'sub_duplicate', 'stripe_status' => 'paused']);
        $remote = ['id' => 'sub_duplicate', 'customer' => 'cus_duplicate', 'status' => 'active', 'items' => ['data' => []]];
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldReceive('forCustomer')->once()->andReturn([$remote, $remote]);
        app()->instance(SubscriptionReader::class, $reader);
        expect(fn () => app(BillingReconciler::class)->reconcile(billingNativeOwner(7)))
            ->toThrow(BillingException::class, 'duplicate subscription');
        expect($subscription->fresh()->stripe_status)->toBe('paused');
    });
});

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
