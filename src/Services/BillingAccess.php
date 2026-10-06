<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use InvalidArgumentException;
use Nvl\Billing\Catalog\PlanCatalog;
use Nvl\Billing\Contracts\BillingAccessContract;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Billing\ValueObjects\BillingSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Resolves tenant features and limits from Cashier's synchronized state.
 *
 * @api
 */
final readonly class BillingAccess implements BillingAccessContract
{
    /** Create the read-only access resolver with the configured Price catalog. */
    public function __construct(private PlanCatalog $catalog) {}

    /** Return one tenant's effective access without using ambient tenant context. */
    public function forTenant(TenantId $tenant): BillingSnapshot
    {
        if (config('nvl-billing.enabled') !== true) {
            return $this->snapshot(null, 'disabled');
        }

        $account = BillingAccount::query()->where('tenant_id', $tenant->value)->first();
        if ($account === null) {
            return $this->snapshot(null, 'free');
        }

        $type = config('nvl-billing.subscription_type', 'default');
        if (! is_string($type) || $type === '') {
            throw new InvalidArgumentException('billing.subscription_type must be a nonempty string.');
        }

        $subscriptions = BillingSubscription::query()->where('billing_account_id', $account->id)->where('type', $type);
        $subscription = (clone $subscriptions)
            ->whereIn('stripe_status', ['active', 'trialing'])
            ->latest('created_at')
            ->first();
        $subscription ??= (clone $subscriptions)
            ->where('stripe_status', 'canceled')
            ->where('ends_at', '>', now())
            ->latest('created_at')
            ->first();
        $subscription ??= $subscriptions->latest('created_at')->first();
        if ($subscription === null) {
            return $this->snapshot(null, 'free');
        }

        $state = $subscription->stripe_status;
        $withinTerm = $subscription->ends_at === null || $subscription->ends_at->isFuture();
        $allowedState = in_array($state, ['active', 'trialing'], true)
            || ($state === 'canceled' && $subscription->ends_at?->isFuture() === true);

        if (! $withinTerm || ! $allowedState) {
            return $this->snapshot(null, $state);
        }

        $items = $subscription->items;
        if ($items->count() !== 1) {
            return $this->snapshot(null, 'unsupported_items');
        }

        $item = $items->first();
        if (! $item instanceof BillingSubscriptionItem) {
            throw new InvalidArgumentException('Cashier is not using the Billing subscription item model.');
        }

        $plan = $this->catalog->planForPrice($item->stripe_price);

        return $this->snapshot($plan, $state);
    }

    /** Create a snapshot from one configured plan's feature policy. */
    private function snapshot(?string $plan, string $state): BillingSnapshot
    {
        $path = $plan === null ? 'nvl-billing.access.free' : "nvl-billing.access.plans.{$plan}";
        $policy = config($path, []);
        if (! is_array($policy)) {
            throw new InvalidArgumentException('Billing access policy must be an array.');
        }

        /** @var list<string> $features */
        $features = $policy['features'] ?? [];
        /** @var array<string, int> $limits */
        $limits = $policy['limits'] ?? [];

        return new BillingSnapshot($plan, $state, $features, $limits);
    }
}
