<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Nvl\Billing\Models\BillingAccount;

/** Updates the trial and pending Checkout markers after Stripe confirms state. */
final class BillingAccountStateUpdater
{
    /**
     * Consume a real trial and clear a matching confirmed Checkout.
     *
     * @param  array<string, mixed>  $subscription
     */
    public function subscriptionSynced(BillingAccount $account, array $subscription, bool $mayClearPending): void
    {
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

        if ($account->isDirty()) {
            $account->save();
        }
    }
}
