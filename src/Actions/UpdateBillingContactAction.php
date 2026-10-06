<?php

declare(strict_types=1);

namespace Nvl\Billing\Actions;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Updates the tenant-owned contact used for Stripe billing correspondence.
 *
 * @api
 */
final readonly class UpdateBillingContactAction
{
    /** Inject tenant, authorization, and Stripe boundaries. */
    public function __construct(
        private TenantDirectory $tenants,
        private BillingManagementAccess $management,
        private BillingGateway $gateway,
    ) {}

    /** Change the contact only after the host authorizes this tenant. */
    public function execute(TenantId $tenant, Authenticatable $actor, string $name, string $email): BillingAccount
    {
        if (config('nvl-billing.enabled') !== true) {
            throw new DomainException('Billing is disabled.');
        }

        $this->management->assertCanManage($actor, $tenant);
        if ($this->tenants->find($tenant)->status !== TenantStatus::Active) {
            throw new DomainException('Only active tenants may change billing contacts.');
        }

        $name = trim($name);
        $email = trim($email);
        if ($name === '' || strlen($name) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new InvalidArgumentException('A valid billing contact name and email are required.');
        }

        $account = BillingAccount::query()->where('tenant_id', $tenant->value)->first();
        if ($account === null) {
            return BillingAccount::query()->create([
                'tenant_id' => $tenant->value,
                'name' => $name,
                'email' => $email,
            ]);
        }

        if ($account->stripe_id !== null) {
            $this->gateway->updateCustomer($account, $name, $email);
        }

        $account->forceFill(['name' => $name, 'email' => $email])->save();

        return $account;
    }
}
