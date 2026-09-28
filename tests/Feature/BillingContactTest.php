<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Actions\UpdateBillingContactAction;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Models\Tenant;
use Nvl\Tenancy\ValueObjects\TenantId;

it('updates only an authorized tenant billing contact and synchronizes Stripe', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'status' => TenantStatus::Active]);
    $tenantId = new TenantId($tenant->id);
    $account = BillingAccount::query()->forceCreate([
        'tenant_id' => $tenantId->value,
        'email' => 'old@example.test',
        'stripe_id' => 'cus_test',
    ]);
    app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
    });
    $gateway = new class implements BillingGateway
    {
        public int $updates = 0;

        public function checkout(BillingAccount $account, string $price, int $trialDays, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $attemptId): CheckoutSession
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

    $updated = app(UpdateBillingContactAction::class)->execute(
        $tenantId,
        new GenericUser(['id' => 'manager']),
        'Acme Billing',
        'billing@example.test',
    );

    expect($updated->id)->toBe($account->id)
        ->and($account->fresh()->name)->toBe('Acme Billing')
        ->and($account->fresh()->email)->toBe('billing@example.test')
        ->and($gateway->updates)->toBe(1);
});

it('creates a contact locally before the tenant has a Stripe customer', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'status' => TenantStatus::Active]);
    $tenantId = new TenantId($tenant->id);
    app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
    });

    $contact = app(UpdateBillingContactAction::class)->execute(
        $tenantId,
        new GenericUser(['id' => 'manager']),
        '  Accounts Team  ',
        '  accounts@example.test  ',
    );

    expect($contact->tenant_id)->toBe($tenantId->value)
        ->and($contact->name)->toBe('Accounts Team')
        ->and($contact->email)->toBe('accounts@example.test')
        ->and($contact->stripe_id)->toBeNull();
});

it('rejects invalid billing contacts without creating an account', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme', 'status' => TenantStatus::Active]);
    app()->instance(BillingManagementAccess::class, new class implements BillingManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, TenantId $tenant): void {}
    });

    expect(fn () => app(UpdateBillingContactAction::class)->execute(
        new TenantId($tenant->id),
        new GenericUser(['id' => 'manager']),
        ' ',
        'not-an-email',
    ))->toThrow(InvalidArgumentException::class)
        ->and(BillingAccount::query()->count())->toBe(0);
});
