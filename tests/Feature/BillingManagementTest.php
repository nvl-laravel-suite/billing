<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Actions\StartCheckoutAction;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Services\BillingPortal;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Models\Tenant;
use Nvl\Tenancy\ValueObjects\TenantId;

it('authorizes one checkout attempt and reuses its pending session', function (): void {
    config()->set('billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
    config()->set('billing.trial.days', 14);
    $tenant = Tenant::query()->create(['name' => 'Acme', 'status' => TenantStatus::Active]);
    $tenantId = new TenantId($tenant->id);
    $actor = new GenericUser(['id' => 'user-1']);

    app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
    });
    $gateway = new class implements BillingGateway
    {
        public int $checkoutCalls = 0;

        public int $lastTrialDays = -1;

        public function checkout(BillingAccount $account, string $price, int $trialDays, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $attemptId): CheckoutSession
        {
            $this->checkoutCalls++;
            $this->lastTrialDays = $trialDays;

            return new CheckoutSession('cs_test', 'https://checkout.stripe.test/session', $expiresAt);
        }

        public function portal(BillingAccount $account, string $returnUrl): string
        {
            return 'https://billing.stripe.test/portal';
        }

        public function updateCustomer(BillingAccount $account, string $name, string $email): void {}
    };
    app()->instance(BillingGateway::class, $gateway);

    $checkout = app(StartCheckoutAction::class);
    $first = $checkout->execute($tenantId, $actor, 'billing@example.test', 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel');
    $second = $checkout->execute($tenantId, $actor, 'billing@example.test', 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel');

    expect($first->url)->toBe($second->url)
        ->and($gateway->checkoutCalls)->toBe(1)
        ->and($gateway->lastTrialDays)->toBe(14)
        ->and(BillingAccount::query()->where('tenant_id', $tenantId->value)->count())->toBe(1);
});

it('denies billing management until the host supplies an authorization adapter', function (): void {
    config()->set('billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
    $tenant = Tenant::query()->create(['name' => 'Acme', 'status' => TenantStatus::Active]);

    expect(fn () => app(StartCheckoutAction::class)->execute(
        new TenantId($tenant->id),
        new GenericUser(['id' => 'user-1']),
        'billing@example.test',
        'pro',
        'monthly',
        'https://app.test/success',
        'https://app.test/cancel',
    ))->toThrow(AuthorizationException::class);
});

it('creates a portal entry only for an authorized tenant with a Stripe customer', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'status' => TenantStatus::Active]);
    $tenantId = new TenantId($tenant->id);
    $actor = new GenericUser(['id' => 'user-1']);
    BillingAccount::query()->forceCreate(['tenant_id' => $tenant->id, 'email' => 'billing@example.test', 'stripe_id' => 'cus_test']);
    app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
    });
    app()->instance(BillingGateway::class, new class implements BillingGateway
    {
        public function checkout(BillingAccount $account, string $price, int $trialDays, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $attemptId): CheckoutSession
        {
            throw new LogicException('Checkout should not be called.');
        }

        public function portal(BillingAccount $account, string $returnUrl): string
        {
            return 'https://billing.stripe.test/portal';
        }

        public function updateCustomer(BillingAccount $account, string $name, string $email): void {}
    });

    expect(app(BillingPortal::class)->url($tenantId, $actor, 'https://app.test/billing'))->toBe('https://billing.stripe.test/portal');
});
