<?php

declare(strict_types=1);
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Services\BillingContacts;
use Nvl\Billing\Testing\FakeBillingGateway;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\ValueObjects\OwnerReference as TenantId;

it('updates only an authorized tenant billing contact and synchronizes Stripe', function (): void {
    billingNativeRun(function () {
        $tenant = Company::query()->findOrFail(7);
        $tenantId = billingNativeOwner(7);
        $account = BillingAccount::query()->forceCreate(['email' => 'old@example.test', 'stripe_id' => 'cus_test']);
        app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
        {
            public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
        });
        $gateway = new class implements BillingGateway
        {
            public int $updates = 0;

            public function checkout(BillingAccount $account, string $price, int $trialDays, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $attemptId): array
            {
                throw new LogicException('Checkout should not be called.');
            }

            public function portal(BillingAccount $account, string $returnUrl): string
            {
                throw new LogicException('Portal should not be called.');
            }

            public function updateCustomer(BillingAccount $account, string $name, string $email): void
            {
                $this->updates++;
            }
        };
        app()->instance(BillingGateway::class, $gateway);
        $updated = app(BillingContacts::class)->update($tenantId, Actor::query()->findOrFail(1), 'Acme Billing', 'billing@example.test');
        expect($updated->id)->toBe($account->id)->and($account->fresh()->name)->toBe('Acme Billing')->and($account->fresh()->email)->toBe('billing@example.test')->and($gateway->updates)->toBe(1);
    });
});
it('creates a contact locally before the tenant has a Stripe customer', function (): void {
    billingNativeRun(function () {
        $tenant = Company::query()->findOrFail(7);
        $tenantId = billingNativeOwner(7);
        app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
        {
            public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
        });
        $contact = app(BillingContacts::class)->update($tenantId, Actor::query()->findOrFail(1), '  Accounts Team  ', '  accounts@example.test  ');
        expect($contact->owner_scope_key)->toBe($tenantId->scopeKey())->and($contact->name)->toBe('Accounts Team')->and($contact->email)->toBe('accounts@example.test')->and($contact->stripe_id)->toBeNull();
    });
});
it('rejects invalid billing contacts without creating an account', function (): void {
    billingNativeRun(function () {
        $tenant = Company::query()->findOrFail(7);
        app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
        {
            public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
        });
        expect(fn () => app(BillingContacts::class)->update(billingNativeOwner(7), Actor::query()->findOrFail(1), ' ', 'not-an-email'))->toThrow(InvalidArgumentException::class)->and(BillingAccount::query()->count())->toBe(0);
    });
});

it('rejects remote contact updates inside an outer transaction without changing either side', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['name' => 'Original', 'email' => 'old@example.test', 'stripe_id' => 'cus_outer_contact']);
        $access = Mockery::mock(BillingManagementAccess::class);
        $access->shouldReceive('assertCanManage')->once();
        app()->instance(BillingManagementAccess::class, $access);
        $gateway = FakeBillingGateway::fake(app())->willReturn('updateCustomer', null);
        $connection = $account->getConnection();
        $owner = billingNativeOwner(7);
        $actor = Actor::query()->findOrFail(1);
        $connection->beginTransaction();
        try {
            expect(fn () => app(BillingContacts::class)->update($owner, $actor, 'Changed', 'changed@example.test'))
                ->toThrow(DomainException::class, 'outside a database transaction');
            expect($gateway->calls('updateCustomer'))->toBe([])
                ->and($account->fresh()->name)->toBe('Original')
                ->and($account->fresh()->email)->toBe('old@example.test');
        } finally {
            $connection->rollBack();
        }
    });
});
