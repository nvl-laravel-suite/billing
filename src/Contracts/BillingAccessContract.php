<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

use Nvl\Billing\ValueObjects\BillingSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Resolves the tenant's effective billing features and limits.
 *
 * @api
 */
interface BillingAccessContract
{
    /** Return one tenant's effective access without using ambient tenant context. */
    public function forTenant(TenantId $tenant): BillingSnapshot;
}
