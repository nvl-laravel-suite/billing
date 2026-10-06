<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Nvl\Billing\Models\BillingAccount;

/** Updates the trial and pending Checkout markers after Stripe confirms state. */
final class BillingAccountStateUpdater
{
    /** Retain the shared committed fact publisher. */
    public function __construct(private readonly BillingEvents $events) {}

    /**
     * Consume a real trial and clear a matching confirmed Checkout.
     *
     * @param  array<string, mixed>  $subscription
     */
    public function subscriptionSynced(BillingAccount $account, array $subscription, bool $mayClearPending): void
    {
        $account->getConnection()->transaction(function () use ($account, $subscription, $mayClearPending): void {
            $account->setRawAttributes($account->newQuery()->whereKey($account->getKey())->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if ($account->trial_consumed_at === null && (
                ($subscription['status'] ?? null) === 'trialing'
                || ($subscription['trial_start'] ?? null) !== null
                || ($subscription['trial_end'] ?? null) !== null
            )) {
                $account->trial_consumed_at = now();
            }

            $prices = [];
            $items = $subscription['items'] ?? null;
            if (is_array($items) && is_array($items['data'] ?? null)) {
                foreach ($items['data'] as $item) {
                    if (is_array($item) && is_array($item['price'] ?? null) && is_string($item['price']['id'] ?? null)) {
                        $prices[] = $item['price']['id'];
                    }
                }
            }
            if ($mayClearPending
                && in_array($subscription['status'] ?? null, ['active', 'trialing'], true)
                && in_array($account->pending_checkout_price, $prices, true)
                && $account->pending_checkout_attempt_id !== null) {
                $account->pending_checkout_attempt_id = null;
                $account->pending_checkout_price = null;
                $account->pending_checkout_session_id = null;
                $account->pending_checkout_url = null;
                $account->pending_checkout_expires_at = null;
                $account->pending_checkout_trial_days = null;
            }

            $trialConsumed = $account->isDirty('trial_consumed_at');
            $checkoutCleared = $account->isDirty('pending_checkout_attempt_id') && $account->pending_checkout_attempt_id === null;
            if ($account->isDirty()) {
                $account->save();
                if ($trialConsumed) {
                    $this->events->accountChanged($account, 'trial_consumed');
                }
                if ($checkoutCleared) {
                    $this->events->accountChanged($account, 'checkout_cleared');
                }
            }
        });
    }
}
