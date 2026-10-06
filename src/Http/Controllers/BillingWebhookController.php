<?php

declare(strict_types=1);

namespace Nvl\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Services\BillingAccountStateUpdater;
use Nvl\Billing\Services\BillingCustomerSyncer;
use Nvl\Billing\Services\BillingSubscriptionSyncer;
use Nvl\Support\Config\PackageStorage;
use Symfony\Component\HttpFoundation\Response;

/** Verifies, deduplicates, and synchronizes tenant Stripe billing events. */
final class BillingWebhookController
{
    /** Inject subscription and account synchronization boundaries. */
    public function __construct(
        private readonly BillingSubscriptionSyncer $subscriptions,
        private readonly BillingAccountStateUpdater $accounts,
        private readonly BillingCustomerSyncer $customers,
    ) {}

    /** Apply one signed Stripe event through Cashier and record its side effects. */
    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null)) {
            return response()->json(['message' => 'Invalid Stripe event.'], 400);
        }
        $connection = PackageStorage::connection('billing');

        return DB::connection($connection)->transaction(function () use ($payload, $connection): Response {
            $inserted = DB::connection($connection)
                ->table(BillingTables::get(BillingTables::WebhookEvents))
                ->insertOrIgnore([
                    'stripe_event_id' => $payload['id'],
                    'type' => $payload['type'],
                    'processed_at' => now(),
                ]);

            if ($inserted === 0) {
                return response()->noContent();
            }

            $event = [];
            foreach ($payload as $key => $value) {
                if (is_string($key)) {
                    $event[$key] = $value;
                }
            }

            $this->applyEvent($event);

            return response()->noContent();
        });
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
