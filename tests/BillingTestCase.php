<?php

declare(strict_types=1);

namespace Nvl\Billing\Tests;

use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\Contracts\OwnerSystemAccess;

/** Installs explicit native host policy for Billing feature scenarios. */
abstract class BillingTestCase extends OwnerScopeTestCase
{
    protected function mayReuseAdoptedSchema(): bool
    {
        return get_parent_class($this) === self::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['nvl-ownership.system_operations' => ['billing.provider-callback', 'billing.reconcile']]);
        $this->app->bind(OwnerSystemAccess::class, static fn () => new class implements OwnerSystemAccess
        {
            public function assertCanRun(string $operation, Model $owner): void {}
        });
        $this->retainAdoptedOwnershipSchema();
    }
}
