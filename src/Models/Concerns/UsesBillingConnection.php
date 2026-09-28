<?php

declare(strict_types=1);

namespace Nvl\Billing\Models\Concerns;

use InvalidArgumentException;

/** Keeps billing records on the central tenant directory connection. */
trait UsesBillingConnection
{
    /** Resolve the explicit central connection selected by Billing or Tenancy. */
    public function getConnectionName(): ?string
    {
        $connection = config('billing.connection') ?? config('tenancy.connection');

        if ($connection !== null && ! is_string($connection)) {
            throw new InvalidArgumentException('billing.connection must be null or a connection name.');
        }

        return $connection ?? parent::getConnectionName();
    }
}
