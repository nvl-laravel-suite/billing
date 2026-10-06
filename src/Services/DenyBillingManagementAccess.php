<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Denies billing changes until the consumer binds its own permission rule. */
final class DenyBillingManagementAccess implements BillingManagementAccess
{
    /** Deny a management action without an installed host adapter. */
    public function assertCanManage(Authenticatable $actor, TenantId $tenant): void
    {
        throw BindingRequiredException::for('billing', BillingManagementAccess::class, 'billing_management');
    }
}
