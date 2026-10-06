<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Nvl\Billing\Events\BillingAccountChanged;
use Nvl\Billing\Events\BillingCheckoutStarted;
use Nvl\Billing\Events\BillingSubscriptionChanged;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Support\Events\DomainEventDispatcher;

/** Publishes bounded Billing facts from reusable local write boundaries. */
final readonly class BillingEvents
{
    /** Retain the native source-aware dispatcher. */
    public function __construct(private DomainEventDispatcher $events) {}

    /** Publish one persisted account transition while Billing is enabled. */
    public function accountChanged(BillingAccount $account, string $operation): void
    {
        if (config('nvl-billing.enabled') === true) {
            $this->events->dispatch(new BillingAccountChanged($account->id, $account->tenant_id, $operation), $account->getConnection());
        }
    }

    /** Publish the first successfully persisted checkout session identity. */
    public function checkoutStarted(BillingAccount $account, string $attemptId, string $sessionId): void
    {
        if (config('nvl-billing.enabled') === true) {
            $this->events->dispatch(new BillingCheckoutStarted($account->id, $account->tenant_id, $attemptId, $sessionId), $account->getConnection());
        }
    }

    /** Publish a subscription or item transition without remote payload fields. */
    public function subscriptionChanged(BillingSubscription $subscription, ?string $previousStatus): void
    {
        if (config('nvl-billing.enabled') === true) {
            $account = BillingAccount::query()->findOrFail($subscription->billing_account_id);
            $this->events->dispatch(new BillingSubscriptionChanged($subscription->id, $account->id, $account->tenant_id, $previousStatus, $subscription->stripe_status), $subscription->getConnection());
        }
    }
}
