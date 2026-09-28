<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Laravel\Cashier\Cashier;
use Nvl\Billing\Contracts\SubscriptionReader;

/** Pages through Stripe's authoritative subscriptions for reconciliation. */
final class StripeSubscriptionReader implements SubscriptionReader
{
    /** @return list<array<string, mixed>> */
    public function forCustomer(string $customerId): array
    {
        $subscriptions = [];

        foreach (Cashier::stripe()->subscriptions->all([
            'customer' => $customerId,
            'status' => 'all',
            'limit' => 100,
        ])->autoPagingIterator() as $subscription) {
            $values = [];
            foreach ($subscription->toArray() as $key => $value) {
                if (is_string($key)) {
                    $values[$key] = $value;
                }
            }

            $subscriptions[] = $values;
        }

        return $subscriptions;
    }
}
