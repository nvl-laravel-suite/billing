<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;

/** Persists signed provider customer changes through atomic local write boundaries. */
final readonly class BillingCustomerSyncer
{
    /** Reuse the same subscription writer and committed fact publisher. */
    public function __construct(private BillingSubscriptionSyncer $subscriptions, private BillingEvents $events) {}

    /** @param array<string, mixed> $customer */
    public function updateCustomer(array $customer): void
    {
        $id = $customer['id'] ?? null;
        if (! is_string($id)) {
            return;
        }

        $account = BillingAccount::query()->where('stripe_id', $id)->first();
        if ($account === null) {
            return;
        }

        $changes = [];
        if (is_string($customer['name'] ?? null) && $customer['name'] !== '') {
            $changes['name'] = $customer['name'];
        }
        if (is_string($customer['email'] ?? null) && filter_var($customer['email'], FILTER_VALIDATE_EMAIL) !== false) {
            $changes['email'] = $customer['email'];
        }
        if ($changes !== []) {
            $account->getConnection()->transaction(function () use ($account, $changes): void {
                $account->setRawAttributes($account->newQuery()->whereKey($account->getKey())->lockForUpdate()->firstOrFail()->getAttributes(), true);
                $account->forceFill($changes);
                if ($account->isDirty(array_keys($changes))) {
                    $account->save();
                    $this->events->accountChanged($account, 'customer_updated');
                }
            });
        }
    }

    /** @param array<string, mixed> $customer */
    public function deleteCustomer(array $customer): void
    {
        $id = $customer['id'] ?? null;
        if (! is_string($id)) {
            return;
        }

        $account = BillingAccount::query()->where('stripe_id', $id)->first();
        if ($account === null) {
            return;
        }

        $account->getConnection()->transaction(function () use ($account): void {
            $account->setRawAttributes($account->newQuery()->whereKey($account->getKey())->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if ($account->stripe_id === null) {
                return;
            }
            foreach (BillingSubscription::query()->where('billing_account_id', $account->id)->get() as $subscription) {
                $this->subscriptions->cancel($subscription, $account);
            }
            $account->forceFill(['stripe_id' => null, 'pm_type' => null, 'pm_last_four' => null])->save();
            $this->events->accountChanged($account, 'customer_deleted');
        });
    }
}
