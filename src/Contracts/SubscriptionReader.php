<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

/** Reads the complete current Stripe subscription list for one customer. */
interface SubscriptionReader
{
    /**
     * Return all current and historical Stripe subscription objects.
     *
     * @return list<array<string, mixed>>
     */
    public function forCustomer(string $customerId): array;
}
