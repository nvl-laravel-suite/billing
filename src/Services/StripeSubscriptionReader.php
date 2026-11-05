<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Laravel\Cashier\Cashier;
use Nvl\Billing\Contracts\SubscriptionReader;

/**
 * Pages through Stripe's authoritative subscriptions for reconciliation.
 *
 * @internal
 */
final class StripeSubscriptionReader implements SubscriptionReader
{
    /** @return list<array<string, mixed>> */
    public function forCustomer(string $customerId): array
    {
        $stripe = Cashier::stripe();
        $subscriptions = [];

        foreach ($stripe->subscriptions->all([
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

            $items = $values['items'] ?? null;
            if (is_array($items) && ($items['has_more'] ?? false) === true) {
                $items['data'] = [];
                foreach ($stripe->subscriptionItems->all([
                    'subscription' => $subscription->id,
                    'limit' => 100,
                ])->autoPagingIterator() as $item) {
                    $items['data'][] = $item->toArray();
                }
                $items['has_more'] = false;
                $values['items'] = $items;
            }

            $subscriptions[] = $values;
        }

        return $subscriptions;
    }
}
