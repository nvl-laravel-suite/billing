<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Authorizes an actor to change one owner's billing relationship.
 *
 * @api
 */
interface BillingManagementAccess
{
    /** Assert that the actor may manage billing for the selected tenant. */
    public function assertCanManage(Authenticatable $actor, OwnerReference $owner): void;
}
