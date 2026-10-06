<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Creates a portal entry for an authorized tenant billing administrator. */
final readonly class BillingPortal
{
    /** Inject permission, tenant, and Stripe boundaries. */
    public function __construct(
        private TenantDirectory $tenants,
        private BillingManagementAccess $management,
        private BillingGateway $gateway,
    ) {}

    /** Return a hosted portal URL without exposing another tenant's customer. */
    public function url(TenantId $tenant, Authenticatable $actor, string $returnUrl): string
    {
        if (config('billing.enabled') !== true) {
            throw new DomainException('Billing is disabled.');
        }

        $this->management->assertCanManage($actor, $tenant);
        $this->tenants->find($tenant);
        $account = BillingAccount::query()->where('tenant_id', $tenant->value)->first();

        if ($account === null || $account->stripe_id === null) {
            throw new DomainException('The tenant has no Stripe billing customer.');
        }

        return $this->gateway->portal($account, $returnUrl);
    }
}
