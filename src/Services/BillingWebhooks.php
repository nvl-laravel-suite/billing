<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Ownership\BillingProviderAdmission;
use Nvl\Support\Config\PackageStorage;
use Stripe\WebhookSignature;

/**
 * Verifies, deduplicates, and synchronizes tenant Stripe billing events.
 *
 * @api
 */
final class BillingWebhooks
{
    /** Inject subscription and account synchronization boundaries. */
    public function __construct(
        private readonly BillingSubscriptionSyncer $subscriptions,
        private readonly BillingAccountStateUpdater $accounts,
        private readonly BillingCustomerSyncer $customers,
        private readonly BillingProviderAdmission $ownership,
    ) {}

    /** Apply one signed Stripe event through Cashier and record its side effects. */
    public function process(string $payload, string $signature): void
    {
        $secret = config('cashier.webhook.secret');
        if (! is_string($secret) || $secret === '') {
            throw new InvalidArgumentException('A Stripe webhook signing secret is required.');
        }
        $tolerance = config('cashier.webhook.tolerance', 300);
        if ($tolerance !== null) {
            if (! is_int($tolerance) && ! is_string($tolerance)) {
                throw new InvalidArgumentException('Webhook tolerance must be a non-negative integer or null.');
            }
            $tolerance = filter_var($tolerance, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($tolerance === false) {
                throw new InvalidArgumentException('Webhook tolerance must be a non-negative integer or null.');
            }
        }
        WebhookSignature::verifyHeader($payload, $signature, $secret, $tolerance);
        $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)
            || ! is_string($payload['id'] ?? null)
            || ! str_starts_with($payload['id'], 'evt_') || strlen($payload['id']) > 255
            || ! is_string($payload['type'] ?? null) || $payload['type'] === '' || strlen($payload['type']) > 255
            || ! is_array($payload['data'] ?? null) || ! is_array($payload['data']['object'] ?? null)
            || ! is_string($payload['data']['object']['id'] ?? null)
            || $payload['data']['object']['id'] === '' || strlen($payload['data']['object']['id']) > 255) {
            throw new InvalidArgumentException('Invalid Stripe event.');
        }
        $eventPayload = [];
        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Invalid Stripe event keys.');
            }
            $eventPayload[$key] = $value;
        }
        $connection = PackageStorage::connection('billing');

        $this->ownership->run($eventPayload, fn () => DB::connection($connection)->transaction(function () use ($payload, $connection, $eventPayload): void {
            $attributes = $this->ownership->receipt($payload['id'], $payload['type']);
            if ($attributes === null) {
                return;
            }
            $inserted = DB::connection($connection)
                ->table(BillingTables::get(BillingTables::WebhookEvents))
                ->insertOrIgnore([
                    ...$attributes,
                    'stripe_event_id' => $payload['id'],
                    'type' => $payload['type'],
                    'processed_at' => now(),
                ]);

            if ($inserted === 0) {
                return;
            }

            $this->applyEvent($eventPayload);

        }));
    }

    /**
     * Apply only Billing-owned Stripe events without outbound API calls.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyEvent(array $payload): void
    {
        $type = $payload['type'];
        if (! is_string($type) || ! in_array($type, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'customer.updated',
            'customer.deleted',
        ], true)) {
            return;
        }

        $data = $payload['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;
        if (! is_array($object)) {
            return;
        }
        $subscription = [];
        foreach ($object as $key => $value) {
            if (is_string($key)) {
                $subscription[$key] = $value;
            }
        }

        if ($type === 'customer.updated') {
            $this->customers->updateCustomer($subscription);

            return;
        }

        if ($type === 'customer.deleted') {
            $this->customers->deleteCustomer($subscription);

            return;
        }

        $customer = $subscription['customer'] ?? null;
        if (! is_string($customer)) {
            return;
        }

        $account = BillingAccount::query()->where('stripe_id', $customer)->first();
        if ($account === null) {
            return;
        }

        $this->subscriptions->sync($subscription);
        $this->accounts->subscriptionSynced($account, $subscription, $type === 'customer.subscription.created');
    }
}
