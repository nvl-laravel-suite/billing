<?php

declare(strict_types=1);

use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Services\BillingAccess;
use Nvl\Tenancy\ValueObjects\TenantId;

it('grants only the purchased plan to its owning tenant', function (): void {
    config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
    config()->set('nvl-billing.access', [
        'free' => ['features' => ['dashboard'], 'limits' => ['seats' => 1]],
        'plans' => ['pro' => ['features' => ['dashboard', 'reports'], 'limits' => ['seats' => 10]]],
    ]);

    $tenantId = new TenantId('1bc8245c-81fe-4ffb-b90a-99088939ed5e');
    $otherTenantId = new TenantId('17d6866e-0263-4764-bd32-dc9d4cb10fe8');
    $account = BillingAccount::query()->create(['tenant_id' => $tenantId->value, 'email' => 'billing@example.test']);
    $subscription = $account->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_active',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_month',
    ]);
    $subscription->items()->create([
        'stripe_id' => 'si_active',
        'stripe_product' => 'prod_pro',
        'stripe_price' => 'price_pro_month',
    ]);

    $access = app(BillingAccess::class);

    expect($access->forTenant($tenantId)->plan)->toBe('pro')
        ->and($access->forTenant($tenantId)->allows('reports'))->toBeTrue()
        ->and($access->forTenant($tenantId)->limit('seats'))->toBe(10)
        ->and($access->forTenant($otherTenantId)->allows('reports'))->toBeFalse()
        ->and($access->forTenant($otherTenantId)->limit('seats'))->toBe(1);
});

it('fails closed for nonpaying subscription states and unknown prices', function (string $status, string $price, bool $expected): void {
    config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
    config()->set('nvl-billing.access', [
        'free' => ['features' => [], 'limits' => []],
        'plans' => ['pro' => ['features' => ['reports'], 'limits' => []]],
    ]);

    $tenantId = new TenantId('1bc8245c-81fe-4ffb-b90a-99088939ed5e');
    $account = BillingAccount::query()->create(['tenant_id' => $tenantId->value, 'email' => 'billing@example.test']);
    $subscription = $account->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test',
        'stripe_status' => $status,
        'stripe_price' => $price,
    ]);
    $subscription->items()->create([
        'stripe_id' => 'si_test',
        'stripe_product' => 'prod_pro',
        'stripe_price' => $price,
    ]);

    expect(app(BillingAccess::class)->forTenant($tenantId)->allows('reports'))->toBe($expected);
})->with([
    'trial' => ['trialing', 'price_pro_month', true],
    'active' => ['active', 'price_pro_month', true],
    'past due' => ['past_due', 'price_pro_month', false],
    'paused' => ['paused', 'price_pro_month', false],
    'incomplete' => ['incomplete', 'price_pro_month', false],
    'unknown price' => ['active', 'price_other', false],
]);

it('uses a new active subscription after an older one was canceled', function (): void {
    config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
    config()->set('nvl-billing.access.plans.pro', ['features' => ['reports'], 'limits' => []]);
    $tenantId = new TenantId('1bc8245c-81fe-4ffb-b90a-99088939ed5e');
    $account = BillingAccount::query()->create(['tenant_id' => $tenantId->value, 'email' => 'billing@example.test']);
    $account->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_old',
        'stripe_status' => 'canceled',
        'ends_at' => now()->subDay(),
    ]);
    $current = $account->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_current',
        'stripe_status' => 'active',
    ]);
    $current->items()->create([
        'stripe_id' => 'si_current',
        'stripe_product' => 'prod_pro',
        'stripe_price' => 'price_pro_month',
    ]);

    expect(app(BillingAccess::class)->forTenant($tenantId)->allows('reports'))->toBeTrue();
});
