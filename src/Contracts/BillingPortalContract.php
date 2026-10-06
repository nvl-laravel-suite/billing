<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Opens the authorized tenant's hosted billing management portal.
 *
 * @api
 */
interface BillingPortalContract
{
    /** Return a hosted portal URL without exposing another tenant's customer. */
    public function url(TenantId $tenant, Authenticatable $actor, string $returnUrl): string;
}
