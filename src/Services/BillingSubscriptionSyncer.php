<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Carbon\CarbonImmutable;
use Nvl\Billing\Enums\BillingResponseCode;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;

/** Applies a signed or fetched Stripe subscription to Cashier-owned models. */
final class BillingSubscriptionSyncer
{
    /** Retain the shared Billing fact publisher. */
    public function __construct(private readonly BillingEvents $events) {}

    /**
     * Synchronize one Stripe subscription without calling Stripe in a DB transaction.
     *
     * @param  array<string, mixed>  $subscription
     */
    public function sync(array $subscription): void
    {
        $customerId = $subscription['customer'] ?? null;
        $subscriptionId = $subscription['id'] ?? null;
        $status = $subscription['status'] ?? null;
        $items = $subscription['items'] ?? null;
        if (! is_string($customerId) || ! is_string($subscriptionId) || ! is_string($status)
            || ! is_array($items) || ! is_array($items['data'] ?? null)) {
            throw BillingException::because(BillingResponseCode::ProviderPayloadInvalid, 'Stripe subscription data is incomplete.');
        }

        $account = BillingAccount::query()->where('stripe_id', $customerId)->first();
        if ($account === null) {
            return;
        }

        $account->getConnection()->transaction(function () use ($account, $subscription, $subscriptionId, $status, $items): void {
            BillingAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $metadata = $subscription['metadata'] ?? null;
            $type = is_array($metadata) && is_string($metadata['type'] ?? null)
                ? $metadata['type']
                : 'default';
            $itemValues = $this->items($items['data']);
            $record = BillingSubscription::query()->firstOrNew(['stripe_id' => $subscriptionId]);
            if ($record->exists && $record->billing_account_id !== $account->id) {
                throw BillingException::because(BillingResponseCode::ProviderIdentityMismatch, 'Stripe subscription belongs to another billing account.');
            }

            $previousStatus = $record->exists ? $record->stripe_status : null;
            $created = ! $record->exists;
            $record->forceFill([
                'billing_account_id' => $account->id,
                'type' => $type,
                'stripe_status' => $status,
                'stripe_price' => count($itemValues) === 1 ? $itemValues[0]['stripe_price'] : null,
                'quantity' => count($itemValues) === 1 ? $itemValues[0]['quantity'] : null,
                'trial_ends_at' => $this->timestamp($subscription['trial_end'] ?? null),
                'ends_at' => $this->endsAt($subscription, $itemValues, $record->ends_at === null ? null : CarbonImmutable::instance($record->ends_at)),
            ]);
            $changed = $created || $record->isDirty();
            $record->save();

            $itemIds = [];
            foreach ($itemValues as $item) {
                $itemIds[] = $item['stripe_id'];
                $existing = BillingSubscriptionItem::query()->where('stripe_id', $item['stripe_id'])->first();
                if ($existing !== null && $existing->billing_subscription_id !== $record->id) {
                    throw BillingException::because(BillingResponseCode::ProviderIdentityMismatch, 'Stripe subscription item belongs to another subscription.');
                }

                $existing ??= new BillingSubscriptionItem;
                $existing->forceFill([
                    'billing_subscription_id' => $record->id,
                    'stripe_id' => $item['stripe_id'],
                    'stripe_product' => $item['stripe_product'],
                    'stripe_price' => $item['stripe_price'],
                    'quantity' => $item['quantity'],
                ]);
                $changed = $changed || ! $existing->exists || $existing->isDirty();
                $existing->save();
            }

            $deleted = BillingSubscriptionItem::query()->where('billing_subscription_id', $record->id)
                ->whereNotIn('stripe_id', $itemIds)->delete();
            if ($changed || $deleted > 0) {
                $this->events->subscriptionChanged($record, $previousStatus);
            }
        });
    }

    /** Cancel a missing or deleted-provider subscription once without restamping replays.
     *
     * @internal
     */
    public function cancel(BillingSubscription $subscription, BillingAccount $account): void
    {
        $subscription->getConnection()->transaction(function () use ($subscription): void {
            $current = BillingSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if ($current->stripe_status === 'canceled' && $current->ends_at !== null) {
                return;
            }
            $previousStatus = $current->stripe_status;
            $current->forceFill(['stripe_status' => 'canceled', 'ends_at' => $current->ends_at ?? now()])->save();
            $this->events->subscriptionChanged($current, $previousStatus);
        });
    }

    /**
     * Validate subscription item fields required by Cashier.
     *
     * @param  array<mixed>  $items
     * @return list<array{stripe_id: string, stripe_product: string, stripe_price: string, quantity: int|null, period_end: CarbonImmutable|null}>
     */
    private function items(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)
                || ! is_array($item['price'] ?? null)
                || ! is_string($item['price']['id'] ?? null)
                || ! is_string($item['price']['product'] ?? null)) {
                throw BillingException::because(BillingResponseCode::ProviderPayloadInvalid, 'Stripe subscription item data is incomplete.');
            }

            $quantity = $item['quantity'] ?? null;
            if ($quantity !== null && ! is_int($quantity)) {
                throw BillingException::because(BillingResponseCode::ProviderPayloadInvalid, 'Stripe subscription item quantity is invalid.');
            }

            $normalized[] = [
                'stripe_id' => $item['id'],
                'stripe_product' => $item['price']['product'],
                'stripe_price' => $item['price']['id'],
                'quantity' => $quantity,
                'period_end' => $this->timestamp($item['current_period_end'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * Determine the paid-through date without querying Stripe.
     *
     * @param  array<string, mixed>  $subscription
     * @param  list<array{stripe_id: string, stripe_product: string, stripe_price: string, quantity: int|null, period_end: CarbonImmutable|null}>  $items
     */
    private function endsAt(array $subscription, array $items, ?CarbonImmutable $existingEnd = null): ?CarbonImmutable
    {
        if (($subscription['status'] ?? null) === 'canceled') {
            return $this->timestamp($subscription['ended_at'] ?? $subscription['canceled_at'] ?? null) ?? $existingEnd ?? CarbonImmutable::now();
        }

        $scheduled = $this->timestamp($subscription['cancel_at'] ?? null);
        if ($scheduled !== null) {
            return $scheduled;
        }

        if (($subscription['cancel_at_period_end'] ?? false) === true) {
            $ends = array_filter(array_column($items, 'period_end'));

            return $ends === [] ? CarbonImmutable::now() : max($ends);
        }

        return null;
    }

    /** Convert a Stripe Unix timestamp into an immutable date. */
    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_int($value) && $value > 0
            ? CarbonImmutable::createFromTimestamp($value)
            : null;
    }
}
