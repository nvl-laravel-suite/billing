<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Repairs a tenant's local Cashier state from Stripe's current objects. */
final readonly class BillingReconciler
{
    /** Inject the Stripe reader and Cashier synchronization adapter. */
    public function __construct(
        private SubscriptionReader $reader,
        private BillingSubscriptionSyncer $syncer,
        private BillingAccountStateUpdater $accounts,
    ) {}

    /** Fetch current Stripe state and apply it atomically to one tenant. */
    public function reconcile(TenantId $tenant): void
    {
        $account = BillingAccount::query()->where('tenant_id', $tenant->value)->first();
        if ($account === null || $account->stripe_id === null) {
            return;
        }

        $remote = $this->reader->forCustomer($account->stripe_id);
        usort($remote, static fn (array $left, array $right): int => ($left['created'] ?? 0) <=> ($right['created'] ?? 0));
        $remoteIds = [];

        DB::connection($account->getConnectionName())->transaction(function () use ($account, $remote, &$remoteIds): void {
            foreach ($remote as $subscription) {
                if (($subscription['customer'] ?? null) !== $account->stripe_id || ! is_string($subscription['id'] ?? null)) {
                    throw new DomainException('Stripe returned a subscription for a different billing customer.');
                }

                $remoteIds[] = $subscription['id'];
                $this->syncer->sync($subscription);
                $this->accounts->subscriptionSynced($account, $subscription, true);
            }

            $missing = $account->subscriptions()->whereNotIn('stripe_id', $remoteIds)->get();
            foreach ($missing as $subscription) {
                $subscription->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()])->save();
            }
        });
    }
}
