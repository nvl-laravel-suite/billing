<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

use Nvl\Billing\ValueObjects\BillingSnapshot;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Resolves the owner's effective billing features and limits.
 *
 * @api
 */
interface BillingAccessContract
{
    /** Return one owner's effective access without using ambient tenant context. */
    public function forOwner(OwnerReference $owner): BillingSnapshot;
}
