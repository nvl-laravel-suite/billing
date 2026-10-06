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
    config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
    config()->set('nvl-billing.trial.days', 14);
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
    config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
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

it('rejects disabled billing, suspended tenants, and invalid checkout email', function (): void {
    config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
    $tenant = Tenant::query()->create(['name' => 'Acme', 'status' => TenantStatus::Suspended]);
    $tenantId = new TenantId($tenant->id);
    $actor = new GenericUser(['id' => 'manager']);
    app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
    });
    $checkout = app(StartCheckoutAction::class);
    $start = fn (string $email): CheckoutSession => $checkout->execute($tenantId, $actor, $email, 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel');

    config()->set('nvl-billing.enabled', false);
    expect(fn () => $start('billing@example.test'))->toThrow(DomainException::class, 'Billing is disabled.');

    config()->set('nvl-billing.enabled', true);
    expect(fn () => $start('billing@example.test'))->toThrow(DomainException::class, 'Only active tenants');

    $tenant->update(['status' => TenantStatus::Active]);
    expect(fn () => $start('invalid'))->toThrow(InvalidArgumentException::class, 'A valid billing email');
});

it('blocks duplicate subscriptions and conflicting pending checkouts', function (): void {
    config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month', 'yearly' => 'price_pro_year']]);
    $tenant = Tenant::query()->create(['name' => 'Acme', 'status' => TenantStatus::Active]);
    $tenantId = new TenantId($tenant->id);
    $account = BillingAccount::query()->create(['tenant_id' => $tenantId->value, 'email' => 'billing@example.test']);
    app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
    });
    $checkout = app(StartCheckoutAction::class);
    $start = fn (): CheckoutSession => $checkout->execute($tenantId, new GenericUser(['id' => 'manager']), 'billing@example.test', 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel');
    $subscription = $account->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_existing', 'stripe_status' => 'active']);

    expect($start)->toThrow(DomainException::class, 'already has a subscription');

    $subscription->delete();
    $account->forceFill([
        'pending_checkout_attempt_id' => 'attempt-other',
        'pending_checkout_price' => 'price_pro_year',
        'pending_checkout_expires_at' => now()->addHour(),
    ])->save();
    expect($start)->toThrow(DomainException::class, 'another plan is already pending');

    $account->forceFill(['pending_checkout_attempt_id' => null])->save();
    config()->set('nvl-billing.trial.days', 1);
    expect($start)->toThrow(InvalidArgumentException::class, 'zero or at least two');
});
