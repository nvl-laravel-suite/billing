<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;

/** Applies a signed or fetched Stripe subscription to Cashier-owned models. */
final class BillingSubscriptionSyncer
{
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
            throw new DomainException('Stripe subscription data is incomplete.');
        }

        $account = BillingAccount::query()->where('stripe_id', $customerId)->first();
        if ($account === null) {
            return;
        }

        $metadata = $subscription['metadata'] ?? null;
        $type = is_array($metadata) && is_string($metadata['type'] ?? null)
            ? $metadata['type']
            : 'default';
        $itemValues = $this->items($items['data']);
        $record = BillingSubscription::query()->firstOrNew(['stripe_id' => $subscriptionId]);
        if ($record->exists && $record->billing_account_id !== $account->id) {
            throw new DomainException('Stripe subscription belongs to another billing account.');
        }

        $record->forceFill([
            'billing_account_id' => $account->id,
            'type' => $type,
            'stripe_status' => $status,
            'stripe_price' => count($itemValues) === 1 ? $itemValues[0]['stripe_price'] : null,
            'quantity' => count($itemValues) === 1 ? $itemValues[0]['quantity'] : null,
            'trial_ends_at' => $this->timestamp($subscription['trial_end'] ?? null),
            'ends_at' => $this->endsAt($subscription, $itemValues),
        ])->save();

        $itemIds = [];
        foreach ($itemValues as $item) {
            $itemIds[] = $item['stripe_id'];
            $existing = BillingSubscriptionItem::query()->where('stripe_id', $item['stripe_id'])->first();
            if ($existing !== null && $existing->billing_subscription_id !== $record->id) {
                throw new DomainException('Stripe subscription item belongs to another subscription.');
            }

            $existing ??= new BillingSubscriptionItem;
            $existing->forceFill([
                'billing_subscription_id' => $record->id,
                'stripe_id' => $item['stripe_id'],
                'stripe_product' => $item['stripe_product'],
                'stripe_price' => $item['stripe_price'],
                'quantity' => $item['quantity'],
            ])->save();
        }

        BillingSubscriptionItem::query()->where('billing_subscription_id', $record->id)
            ->whereNotIn('stripe_id', $itemIds)->delete();
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
                throw new DomainException('Stripe subscription item data is incomplete.');
            }

            $quantity = $item['quantity'] ?? null;
            if ($quantity !== null && ! is_int($quantity)) {
                throw new DomainException('Stripe subscription item quantity is invalid.');
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
    private function endsAt(array $subscription, array $items): ?CarbonImmutable
    {
        if (($subscription['status'] ?? null) === 'canceled') {
            return $this->timestamp($subscription['ended_at'] ?? $subscription['canceled_at'] ?? null) ?? CarbonImmutable::now();
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
