<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Opens the authorized owner's hosted billing management portal.
 *
 * @api
 */
interface BillingPortalContract
{
    /** Return a hosted portal URL without exposing another owner's customer. */
    public function url(OwnerReference $owner, Authenticatable $actor, string $returnUrl): string;
}
