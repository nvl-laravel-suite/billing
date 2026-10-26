<?php

declare(strict_types=1);
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Services\BillingCheckout;
use Nvl\Billing\Services\BillingPortal;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Support\Ownership\ValueObjects\OwnerReference as TenantId;

it('authorizes one checkout attempt and reuses its pending session', function (): void {
    billingNativeRun(function () {
        config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
        config()->set('nvl-billing.trial.days', 14);
        $tenant = Company::query()->findOrFail(7);
        $tenantId = billingNativeOwner(7);
        $actor = Actor::query()->findOrFail(1);
        app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
        {
            public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
        });
        $gateway = new class implements BillingGateway
        {
            public int $checkoutCalls = 0;

            public int $lastTrialDays = -1;

            public function checkout(BillingAccount $account, string $price, int $trialDays, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $attemptId): array
            {
                $this->checkoutCalls++;
                $this->lastTrialDays = $trialDays;

                return ['id' => 'cs_test', 'url' => 'https://checkout.stripe.test/session', 'expiresAt' => $expiresAt];
            }

            public function portal(BillingAccount $account, string $returnUrl): string
            {
                return 'https://billing.stripe.test/portal';
            }

            public function updateCustomer(BillingAccount $account, string $name, string $email): void {}
        };
        app()->instance(BillingGateway::class, $gateway);
        $checkout = app(BillingCheckout::class);
        $first = $checkout->start($tenantId, $actor, 'billing@example.test', 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel');
        $second = $checkout->start($tenantId, $actor, 'billing@example.test', 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel');
        expect($first['url'])->toBe($second['url'])->and($gateway->checkoutCalls)->toBe(1)->and($gateway->lastTrialDays)->toBe(14)->and(BillingAccount::query()->where('owner_scope_key', $tenantId->scopeKey())->count())->toBe(1);
    });
});
it('denies billing management until the host supplies an authorization adapter', function (): void {
    billingNativeRun(function () {
        config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
        $tenant = Company::query()->findOrFail(7);
        expect(fn () => app(BillingCheckout::class)->start(billingNativeOwner(7), Actor::query()->findOrFail(1), 'billing@example.test', 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel'))->toThrow(BindingRequiredException::class);
    });
});
it('creates a portal entry only for an authorized tenant with a Stripe customer', function (): void {
    billingNativeRun(function () {
        $tenant = Company::query()->findOrFail(7);
        $tenantId = billingNativeOwner(7);
        $actor = Actor::query()->findOrFail(1);
        BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_test']);
        app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
        {
            public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
        });
        app()->instance(BillingGateway::class, new class implements BillingGateway
        {
            public function checkout(BillingAccount $account, string $price, int $trialDays, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $attemptId): array
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
});
it('rejects disabled billing and invalid checkout email', function (): void {
    billingNativeRun(function () {
        config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
        $tenant = Company::query()->findOrFail(7);
        $tenantId = billingNativeOwner(7);
        $actor = Actor::query()->findOrFail(1);
        app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
        {
            public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
        });
        $checkout = app(BillingCheckout::class);
        $start = fn (string $email): array => $checkout->start($tenantId, $actor, $email, 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel');
        config()->set('nvl-billing.enabled', false);
        expect(fn () => $start('billing@example.test'))->toThrow(function (BillingException $failure): void {
            expect($failure)->toBeInstanceOf(DomainException::class)->and($failure->responseCode())->toBe('feature_disabled')->and($failure->suggestedStatus())->toBe(404)->and($failure->getMessage())->toContain('Billing is disabled.');
        });
        config()->set('nvl-billing.enabled', true);
        expect(fn () => $start('invalid'))->toThrow(InvalidArgumentException::class, 'A valid billing email');
    });
});
it('blocks duplicate subscriptions and conflicting pending checkouts', function (): void {
    billingNativeRun(function () {
        config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month', 'yearly' => 'price_pro_year']]);
        $tenant = Company::query()->findOrFail(7);
        $tenantId = billingNativeOwner(7);
        $account = BillingAccount::query()->create(['email' => 'billing@example.test']);
        app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
        {
            public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
        });
        $checkout = app(BillingCheckout::class);
        $start = fn (): array => $checkout->start($tenantId, Actor::query()->findOrFail(1), 'billing@example.test', 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel');
        $subscription = BillingSubscription::factory()->forAccount($account)->create(['type' => 'default', 'stripe_id' => 'sub_existing', 'stripe_status' => 'active']);
        expect($start)->toThrow(function (BillingException $failure): void {
            expect($failure)->toBeInstanceOf(DomainException::class)->and($failure->responseCode())->toBe('subscription_conflict')->and($failure->suggestedStatus())->toBe(409)->and($failure->getMessage())->toContain('already has a subscription');
        });
        $subscription->delete();
        $account->forceFill(['pending_checkout_attempt_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'pending_checkout_price' => 'price_pro_year', 'pending_checkout_expires_at' => now()->addHour()])->save();
        expect($start)->toThrow(function (BillingException $failure): void {
            expect($failure)->toBeInstanceOf(DomainException::class)->and($failure->responseCode())->toBe('checkout_conflict')->and($failure->suggestedStatus())->toBe(409)->and($failure->getMessage())->toContain('another plan is already pending');
        });
        $account->forceFill(['pending_checkout_attempt_id' => null])->save();
        config()->set('nvl-billing.trial.days', 1);
        try {
            $start();
            $this->fail('Expected invalid Billing configuration.');
        } catch (BillingException $exception) {
            expect($exception->responseCode())->toBe('invalid_configuration')->and($exception->suggestedStatus())->toBe(500);
        }
    });
});
