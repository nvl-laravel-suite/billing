<?php

declare(strict_types=1);

namespace Nvl\Billing\Models\Concerns;

use Nvl\Support\Config\PackageStorage;

/** Keeps billing records on the central tenant directory connection. */
trait UsesBillingConnection
{
    /** Resolve the explicit central connection selected by Billing or Tenancy. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('billing') ?? parent::getConnectionName());
    }
}
