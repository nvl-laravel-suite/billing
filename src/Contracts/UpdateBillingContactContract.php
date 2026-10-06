<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Updates the authorized tenant's billing correspondence contact.
 *
 * @api
 */
interface UpdateBillingContactContract
{
    /** Change the contact only after the host authorizes this tenant. */
    public function execute(TenantId $tenant, Authenticatable $actor, string $name, string $email): BillingAccount;
}
