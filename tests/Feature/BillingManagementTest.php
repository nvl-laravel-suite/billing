<?php

declare(strict_types=1);
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Services\BillingCheckout;
use Nvl\Billing\Services\BillingContacts;
use Nvl\Billing\Services\BillingPortal;
use Nvl\Billing\Testing\FakeBillingGateway;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Support\Ownership\ValueObjects\OwnerReference as TenantId;

it('binds cached and recoverable checkout attempts to their complete original request', function (bool $cached, string $changed): void {
    billingNativeRun(function () use ($cached, $changed): void {
        config(['nvl-billing.trial.days' => 14, 'nvl-billing.subscription_type' => 'default', 'nvl-billing.trial.require_payment_method' => true]);
        app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
        {
            public function assertCanManage(Authenticatable $actor, TenantId $owner): void {}
        });
        $session = ['id' => 'cs_replay', 'url' => 'https://checkout.stripe.test/replay', 'expiresAt' => CarbonImmutable::now()->addHours(2)];
        $gateway = FakeBillingGateway::fake(app());
        if ($cached) {
            $gateway->willReturn('checkout', $session);
        } else {
            $gateway->willThrow('checkout', new RuntimeException('Provider outcome unknown'));
            $gateway->willReturn('checkout', $session);
        }
        $checkout = app(BillingCheckout::class);
        $start = fn (string $email = 'billing@example.test', string $success = 'https://app.test/success', string $cancel = 'https://app.test/cancel'): array => $checkout->start(billingNativeOwner(), Actor::query()->findOrFail(1), $email, 'pro', 'monthly', $success, $cancel);
        if ($cached) {
            $start();
        } else {
            expect(fn () => $start())->toThrow(RuntimeException::class, 'outcome unknown');
        }
        $account = BillingAccount::query()->sole();
        $original = $account->getRawOriginal();
        if ($changed === 'subscription_type') {
            config(['nvl-billing.subscription_type' => 'other']);
        } elseif ($changed === 'payment_method') {
            config(['nvl-billing.trial.require_payment_method' => false]);
        }
        $request = match ($changed) {
            'success' => fn (): array => $start(success: 'https://app.test/other-success'),
            'cancel' => fn (): array => $start(cancel: 'https://app.test/other-cancel'),
            'email' => fn (): array => $start(email: 'other@example.test'),
            default => fn (): array => $start(),
        };
        expect($request)->toThrow(function (BillingException $exception): void {
            expect($exception->responseCode())->toBe('checkout_conflict');
        })->and($gateway->calls('checkout'))->toHaveCount(1)
            ->and($account->fresh()->getRawOriginal())->toBe($original);

        config(['nvl-billing.subscription_type' => 'default', 'nvl-billing.trial.require_payment_method' => true]);
        expect($start()['id'])->toBe('cs_replay')
            ->and($gateway->calls('checkout'))->toHaveCount($cached ? 1 : 2);
        if (! $cached) {
            expect($gateway->calls('checkout')[1]->arguments['attemptId'])->toBe($gateway->calls('checkout')[0]->arguments['attemptId']);
        }
    });
})->with([false, true])->with(['success', 'cancel', 'email', 'subscription_type', 'payment_method']);

it('rejects unsafe billing return URLs before creating accounts or calling gateways', function (string $url): void {
    billingNativeRun(function () use ($url): void {
        $access = Mockery::mock(BillingManagementAccess::class);
        $access->shouldReceive('assertCanManage');
        app()->instance(BillingManagementAccess::class, $access);
        app()->instance(BillingGateway::class, Mockery::mock(BillingGateway::class));
        $owner = billingNativeOwner(7);
        $actor = Actor::query()->findOrFail(1);
        expect(fn () => app(BillingCheckout::class)->start($owner, $actor, 'billing@example.test', 'pro', 'monthly', $url, 'https://app.test/cancel'))->toThrow(InvalidArgumentException::class);
        expect(fn () => app(BillingCheckout::class)->start($owner, $actor, 'billing@example.test', 'pro', 'monthly', 'https://app.test/success', $url))->toThrow(InvalidArgumentException::class);
        expect(BillingAccount::query()->count())->toBe(0);
        BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_return']);
        expect(fn () => app(BillingPortal::class)->url($owner, $actor, $url))->toThrow(InvalidArgumentException::class);
    });
})->with(['http://app.test/return', 'https://evil.test/return', 'https://app.test.evil.test', 'https://user:secret@app.test', '//app.test/path', 'https://app.test:444/path']);

it('refuses unverified historical checkout attempts until expiry without reusing their provider key', function (): void {
    billingNativeRun(function (): void {
        app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
        {
            public function assertCanManage(Authenticatable $actor, TenantId $owner): void {}
        });
        $gateway = FakeBillingGateway::fake(app())->willReturn('checkout', [
            'id' => 'cs_original', 'url' => 'https://checkout.stripe.test/original', 'expiresAt' => CarbonImmutable::now()->addHours(2),
        ])->willReturn('checkout', [
            'id' => 'cs_after_expiry', 'url' => 'https://checkout.stripe.test/after-expiry', 'expiresAt' => CarbonImmutable::now()->addHours(5),
        ]);
        $checkout = app(BillingCheckout::class);
        $start = fn (string $success = 'https://app.test/success'): array => $checkout->start(billingNativeOwner(), Actor::query()->findOrFail(1), 'billing@example.test', 'pro', 'monthly', $success, 'https://app.test/cancel');
        $start();
        $account = BillingAccount::query()->sole();
        $originalAttempt = $account->pending_checkout_attempt_id;
        $account->forceFill(['pending_checkout_request_hash' => null])->save();

        expect(fn () => $start())->toThrow(BillingException::class, 'unverified')
            ->and($gateway->calls('checkout'))->toHaveCount(1)
            ->and($account->fresh()->pending_checkout_attempt_id)->toBe($originalAttempt);
        try {
            $this->travel(3)->hours();
            expect($start('https://app.test/new-success')['id'])->toBe('cs_after_expiry')
                ->and($account->fresh()->pending_checkout_attempt_id)->not->toBe($originalAttempt)
                ->and($account->fresh()->pending_checkout_request_hash)->toHaveLength(64)
                ->and($gateway->calls('checkout'))->toHaveCount(2);
        } finally {
            $this->travelBack();
        }
    });
});

it('recovers the original request after its provider customer was created during an uncertain attempt', function (): void {
    billingNativeRun(function (): void {
        app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
        {
            public function assertCanManage(Authenticatable $actor, TenantId $owner): void {}
        });
        $attempts = [];
        $gateway = Mockery::mock(BillingGateway::class);
        $gateway->shouldReceive('checkout')->twice()->andReturnUsing(function (BillingAccount $account, string $price, int $trialDays, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $attemptId) use (&$attempts): array {
            $attempts[] = $attemptId;
            if (count($attempts) === 1) {
                $account->forceFill(['stripe_id' => 'cus_created_during_attempt'])->save();
                throw new RuntimeException('Provider outcome unknown');
            }
            expect($account->stripe_id)->toBe('cus_created_during_attempt');

            return ['id' => 'cs_recovered_customer', 'url' => 'https://checkout.stripe.test/recovered-customer', 'expiresAt' => $expiresAt];
        });
        app()->instance(BillingGateway::class, $gateway);
        $start = fn (): array => app(BillingCheckout::class)->start(billingNativeOwner(), Actor::query()->findOrFail(1), 'billing@example.test', 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel');

        expect($start)->toThrow(RuntimeException::class, 'outcome unknown');
        expect($start()['id'])->toBe('cs_recovered_customer')->and($attempts)->toHaveCount(2)
            ->and($attempts[1])->toBe($attempts[0]);
    });
});

it('retains checkout expiry identity when PostgreSQL uses a nonUTC session timezone', function (string $timezone): void {
    $connection = (new BillingAccount)->getConnection();
    if ($connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL session-timezone storage regression.');
    }
    $original = $connection->scalar('SHOW TimeZone');
    try {
        $connection->selectOne("SELECT set_config('TimeZone', ?, false)", [$timezone]);
        billingNativeRun(function (): void {
            app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
            {
                public function assertCanManage(Authenticatable $actor, TenantId $owner): void {}
            });
            $gateway = FakeBillingGateway::fake(app())->willThrow('checkout', new RuntimeException('Provider outcome unknown'))
                ->willReturn('checkout', ['id' => 'cs_timezone_recovered', 'url' => 'https://checkout.stripe.test/timezone', 'expiresAt' => CarbonImmutable::now()->addHours(2)]);
            $start = fn (): array => app(BillingCheckout::class)->start(billingNativeOwner(), Actor::query()->findOrFail(1), 'billing@example.test', 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel');
            expect($start)->toThrow(RuntimeException::class, 'outcome unknown');
            expect(BillingAccount::query()->sole()->pending_checkout_expires_at?->timestamp)->toBe($gateway->calls('checkout')[0]->arguments['expiresAt']->timestamp)
                ->and($start()['id'])->toBe('cs_timezone_recovered')
                ->and($gateway->calls('checkout')[1]->arguments['attemptId'])->toBe($gateway->calls('checkout')[0]->arguments['attemptId']);
        });
    } finally {
        $connection->selectOne("SELECT set_config('TimeZone', ?, false)", [$original]);
    }
})->with(['Europe/Sofia', 'America/New_York']);

it('uses fresh admitted actor facts before billing management policies', function (string $operation): void {
    billingNativeRun(function () use ($operation): void {
        BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_actor']);
        $actor = Actor::query()->findOrFail(1);
        $actor->name = 'Forged administrator';
        app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
        {
            public function assertCanManage(Authenticatable $actor, TenantId $owner): void
            {
                if (! $actor instanceof Actor || $actor->name !== 'Forged administrator') {
                    throw new AuthorizationException;
                }
            }
        });
        app()->instance(BillingGateway::class, Mockery::mock(BillingGateway::class));
        $owner = billingNativeOwner(7);
        expect(fn () => match ($operation) {
            'checkout' => app(BillingCheckout::class)->start($owner, $actor, 'billing@example.test', 'pro', 'monthly', 'https://app.test/success', 'https://app.test/cancel'),
            'contact' => app(BillingContacts::class)->update($owner, $actor, 'Changed', 'changed@example.test'),
            'portal' => app(BillingPortal::class)->url($owner, $actor, 'https://app.test/billing'),
        })->toThrow(AuthorizationException::class);
    });
})->with(['checkout', 'contact', 'portal']);

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
