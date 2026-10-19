<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Illuminate\Support\Facades\DB;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Enums\BillingResponseCode;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use TypeError;

/** Repairs a owner's local Cashier state from Stripe's current objects. */
final readonly class BillingReconciler
{
    /** Inject the Stripe reader and Cashier synchronization adapter. */
    public function __construct(
        private SubscriptionReader $reader,
        private BillingOwnerBoundary $ownership,
        private BillingSubscriptionSyncer $syncer,
        private BillingAccountStateUpdater $accounts,
    ) {}

    /** Fetch current Stripe state and apply it atomically to one owner. */
    public function reconcile(OwnerReference $owner): void
    {
        $this->ownership->assertOwner($owner);
        $account = BillingAccount::query()->where('owner_scope_key', $owner->scopeKey())->first();
        if ($account === null || $account->stripe_id === null) {
            return;
        }

        $remote = $this->reader->forCustomer($account->stripe_id);
        usort($remote, static fn (array $left, array $right): int => ($left['created'] ?? 0) <=> ($right['created'] ?? 0));
        $remoteIds = [];

        DB::connection($account->getConnectionName())->transaction(function () use ($account, $remote, &$remoteIds): void {
            foreach ($remote as $subscription) {
                if (($subscription['customer'] ?? null) !== $account->stripe_id || ! is_string($subscription['id'] ?? null)) {
                    throw BillingException::because(BillingResponseCode::ProviderIdentityMismatch, 'Stripe returned a subscription for a different billing customer.');
                }

                $remoteIds[] = $subscription['id'];
                $this->syncer->sync($subscription);
                $this->accounts->subscriptionSynced($account, $subscription, true);
            }

            $missing = $account->subscriptions()->whereNotIn('stripe_id', $remoteIds)->get();
            foreach ($missing as $subscription) {
                if (! $subscription instanceof BillingSubscription) {
                    throw new TypeError('Billing reconciliation requires the configured Billing subscription model.');
                }
                $this->syncer->cancel($subscription, $account);
            }
        });
    }
}
