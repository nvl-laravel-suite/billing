<?php

declare(strict_types=1);
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Billing\Services\BillingAccess;

it('rejects malformed feature and limit policies at the native read boundary', function (array $policy): void {
    billingNativeRun(function () use ($policy): void {
        config()->set('nvl-billing.access.free', $policy);
        expect(fn () => app(BillingAccess::class)->forOwner(billingNativeOwner(7)))
            ->toThrow(BillingException::class, 'Billing access');
    });
})->with([
    [['features' => 'reports', 'limits' => []]],
    [['features' => [123], 'limits' => []]],
    [['features' => [], 'limits' => ['seats' => '10']]],
    [['features' => [], 'limits' => [10]]],
]);

it('grants only the purchased plan to its owning tenant', function (): void {
    billingNativeRun(function () {
        config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
        config()->set('nvl-billing.access', ['free' => ['features' => ['dashboard'], 'limits' => ['seats' => 1]], 'plans' => ['pro' => ['features' => ['dashboard', 'reports'], 'limits' => ['seats' => 10]]]]);
        $tenantId = billingNativeOwner(7);
        $otherTenantId = billingNativeOwner(8);
        $account = BillingAccount::query()->create(['email' => 'billing@example.test']);
        $subscription = BillingSubscription::factory()->forAccount($account)->create(['type' => 'default', 'stripe_id' => 'sub_active', 'stripe_status' => 'active', 'stripe_price' => 'price_pro_month']);
        BillingSubscriptionItem::factory()->forSubscription($subscription)->create(['stripe_id' => 'si_active', 'stripe_product' => 'prod_pro', 'stripe_price' => 'price_pro_month']);
        $access = app(BillingAccess::class);
        expect($access->forOwner($tenantId)['plan'])->toBe('pro')->and(in_array('reports', $access->forOwner($tenantId)['features'], true))->toBeTrue()->and($access->forOwner($tenantId)['limits']['seats'] ?? 0)->toBe(10);
    });
});
it('fails closed for nonpaying subscription states and unknown prices', function (string $status, string $price, bool $expected): void {
    billingNativeRun(function () use ($status, $price, $expected) {
        config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
        config()->set('nvl-billing.access', ['free' => ['features' => [], 'limits' => []], 'plans' => ['pro' => ['features' => ['reports'], 'limits' => []]]]);
        $tenantId = billingNativeOwner(7);
        $account = BillingAccount::query()->create(['email' => 'billing@example.test']);
        $subscription = BillingSubscription::factory()->forAccount($account)->create(['type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => $status, 'stripe_price' => $price]);
        BillingSubscriptionItem::factory()->forSubscription($subscription)->create(['stripe_id' => 'si_test', 'stripe_product' => 'prod_pro', 'stripe_price' => $price]);
        expect(in_array('reports', app(BillingAccess::class)->forOwner($tenantId)['features'], true))->toBe($expected);
    });
})->with(['trial' => ['trialing', 'price_pro_month', true], 'active' => ['active', 'price_pro_month', true], 'past due' => ['past_due', 'price_pro_month', false], 'paused' => ['paused', 'price_pro_month', false], 'incomplete' => ['incomplete', 'price_pro_month', false], 'unknown price' => ['active', 'price_other', false]]);
it('uses a new active subscription after an older one was canceled', function (): void {
    billingNativeRun(function () {
        config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
        config()->set('nvl-billing.access.plans.pro', ['features' => ['reports'], 'limits' => []]);
        $tenantId = billingNativeOwner(7);
        $account = BillingAccount::query()->create(['email' => 'billing@example.test']);
        BillingSubscription::factory()->forAccount($account)->create(['type' => 'default', 'stripe_id' => 'sub_old', 'stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);
        $current = BillingSubscription::factory()->forAccount($account)->create(['type' => 'default', 'stripe_id' => 'sub_current', 'stripe_status' => 'active']);
        BillingSubscriptionItem::factory()->forSubscription($current)->create(['stripe_id' => 'si_current', 'stripe_product' => 'prod_pro', 'stripe_price' => 'price_pro_month']);
        expect(in_array('reports', app(BillingAccess::class)->forOwner($tenantId)['features'], true))->toBeTrue();
    });
});
