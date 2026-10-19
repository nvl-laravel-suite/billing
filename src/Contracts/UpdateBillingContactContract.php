<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Updates the authorized owner's billing correspondence contact.
 *
 * @api
 */
interface UpdateBillingContactContract
{
    /** Change the contact only after the host authorizes this owner. */
    public function execute(OwnerReference $owner, Authenticatable $actor, string $name, string $email): BillingAccount;
}
