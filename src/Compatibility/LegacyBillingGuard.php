<?php

declare(strict_types=1);

namespace Nvl\Billing\Compatibility;

use Illuminate\Container\Container;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;

/** Prevents the frozen tenancy API from becoming a second native admission path. @internal */
final readonly class LegacyBillingGuard
{
    /** Probe declarations and actual account storage before any legacy workflow or policy callback. */
    public static function assertAvailable(): void
    {
        $resources = Container::getInstance()->make(OwnershipResourceRegistry::class);
        foreach ($resources->all() as $resource) {
            if ($resource->family === 'billing' && $resource->ownership->strategy !== 'unscoped') {
                throw new OwnershipViolation('Native Billing cannot use the legacy tenancy API.');
            }
        }
        $model = new BillingAccount;
        (new PersistedOwnershipStorage)->assertUnadopted($model->getConnection(), resource: 'billing.accounts', physicalTable: $model->getTable());
    }
}
