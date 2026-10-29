<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use DomainException;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Enums\BillingResponseCode;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Repairs a owner's local Cashier state from Stripe's current objects.
 *
 * @api
 */
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
        if (config('nvl-billing.enabled') !== true) {
            throw BillingException::because(BillingResponseCode::FeatureDisabled, 'Billing is disabled.');
        }
        if (BillingModelRegistry::newAccount()->getConnection()->transactionLevel() !== 0) {
            throw new DomainException('Billing reconciliation must start outside a database transaction.');
        }
        $this->ownership->assertOwner($owner);
        $account = BillingModelRegistry::accountQuery()->where('owner_scope_key', $owner->scopeKey())->first();
        if ($account === null || $account->stripe_id === null || $account->provider_deleted_at !== null) {
            return;
        }

        $observedAccount = $account->getRawOriginal();
        $observedSubscriptions = $this->subscriptionState($account);
        $remote = $this->reader->forCustomer($account->stripe_id);
        usort($remote, static fn (array $left, array $right): int => ($left['created'] ?? 0) <=> ($right['created'] ?? 0));

        $account->getConnection()->transaction(function () use ($account, $remote, $observedAccount, $observedSubscriptions): void {
            $remoteIds = [];
            $current = BillingModelRegistry::accountQuery()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($current->getRawOriginal() !== $observedAccount || $this->subscriptionState($current, true) !== $observedSubscriptions) {
                throw BillingException::because(BillingResponseCode::ReconciliationRequired, 'Billing changed during reconciliation; retry with current provider facts.');
            }
            foreach ($remote as $subscription) {
                if (($subscription['customer'] ?? null) !== $account->stripe_id || ! is_string($subscription['id'] ?? null)) {
                    throw BillingException::because(BillingResponseCode::ProviderIdentityMismatch, 'Stripe returned a subscription for a different billing customer.');
                }
                if ($subscription['id'] === '' || in_array($subscription['id'], $remoteIds, true)) {
                    throw BillingException::because(BillingResponseCode::ProviderPayloadInvalid, 'Stripe returned invalid or duplicate subscription identities.');
                }

                $remoteIds[] = $subscription['id'];
                $this->syncer->sync($subscription);
                $this->accounts->subscriptionSynced($account, $subscription, true);
            }

            $missing = $account->subscriptions()->whereNotIn('stripe_id', $remoteIds)->get();
            foreach ($missing as $subscription) {
                $this->syncer->cancel($subscription, $account);
            }
        });
    }

    /**
     * Read or lock complete subscription facts in two ordered queries.
     *
     * @return array{subscriptions:list<array<string,mixed>>,items:list<array<string,mixed>>}
     */
    private function subscriptionState(BillingAccount $account, bool $lock = false): array
    {
        $query = $account->subscriptions()->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $subscriptions = $query->get();
        $items = BillingSubscriptionItem::query()->whereIn('billing_subscription_id', $subscriptions->modelKeys())->orderBy('id');
        if ($lock) {
            $items->lockForUpdate();
        }

        return [
            'subscriptions' => array_values($subscriptions->map(static fn (BillingSubscription $subscription): array => $subscription->getRawOriginal())->all()),
            'items' => array_values($items->get()->map(static fn (BillingSubscriptionItem $item): array => $item->getRawOriginal())->all()),
        ];
    }
}
