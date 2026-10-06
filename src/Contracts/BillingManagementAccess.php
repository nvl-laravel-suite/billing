<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Authorizes an actor to change one tenant's billing relationship.
 *
 * @api
 */
interface BillingManagementAccess
{
    /** Assert that the actor may manage billing for the selected tenant. */
    public function assertCanManage(Authenticatable $actor, TenantId $tenant): void;
}
