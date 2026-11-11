<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Enums\BillingResponseCode;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Ownership\BillingProviderAdmission;
use Nvl\Support\Config\PackageStorage;
use Stripe\WebhookSignature;

/**
 * Verifies, deduplicates, and synchronizes tenant Stripe billing events.
 *
 * @api
 *
 * @phpstan-type SubscriptionState array{attributes:array<string,mixed>|null,items:list<array<string,mixed>>}
 * @phpstan-type SubscriptionRefresh array{account:BillingAccount,subscriptionId:string,observedAccount:array<string,mixed>,observedSubscription:SubscriptionState,snapshot:array<string,mixed>|null}
 */
final class BillingWebhooks
{
    /** Provider reads one subscription callback may spend waiting out concurrent local writers. */
    private const int REFRESH_ATTEMPTS = 3;

    /** Inject subscription and account synchronization boundaries. */
    public function __construct(
        private readonly BillingSubscriptionSyncer $subscriptions,
        private readonly BillingAccountStateUpdater $accounts,
        private readonly BillingCustomerSyncer $customers,
        private readonly BillingProviderAdmission $ownership,
        private readonly SubscriptionReader $reader,
    ) {}

    /** Apply one signed Stripe event through Cashier and record its side effects. */
    public function process(string $payload, string $signature): void
    {
        if (config('nvl-billing.enabled') !== true) {
            throw BillingException::because(BillingResponseCode::FeatureDisabled, 'Billing is disabled.');
        }
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
        $subscriptionEvent = in_array($payload['type'], ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true);
        if ($subscriptionEvent && (BillingModelRegistry::newAccount())->getConnection()->transactionLevel() !== 0) {
            throw new DomainException('Billing subscription refresh must start outside a database transaction.');
        }

        $this->ownership->run($eventPayload, function () use ($payload, $connection, $eventPayload, $subscriptionEvent): void {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $this->applyOnce($payload['id'], $payload['type'], $connection, $eventPayload, $subscriptionEvent);

                    return;
                } catch (BillingException $exception) {
                    if (! $subscriptionEvent || $attempt === self::REFRESH_ATTEMPTS
                        || $exception->responseCode() !== BillingResponseCode::ReconciliationRequired->value) {
                        throw $exception;
                    }
                }
            }
        });
    }

    /**
     * Record and apply the event once; a concurrent local change rolls the receipt back so the
     * caller can observe the committed state and read current provider facts again.
     *
     * @param  array<string, mixed>  $eventPayload
     */
    private function applyOnce(string $eventId, string $type, ?string $connection, array $eventPayload, bool $subscriptionEvent): void
    {
        if ($this->ownership->receipt($eventId, $type) === null) {
            return;
        }
        $refresh = $subscriptionEvent ? $this->refreshSubscription($eventPayload) : null;
        DB::connection($connection)->transaction(function () use ($eventId, $type, $connection, $eventPayload, $subscriptionEvent, $refresh): void {
            $attributes = $this->ownership->receipt($eventId, $type);
            if ($attributes === null) {
                return;
            }
            $inserted = DB::connection($connection)
                ->table(BillingTables::get(BillingTables::WebhookEvents))
                ->insertOrIgnore([
                    ...$attributes,
                    'stripe_event_id' => $eventId,
                    'type' => $type,
                    'processed_at' => now(),
                ]);

            if ($inserted === 0) {
                return;
            }

            if ($subscriptionEvent) {
                if ($refresh !== null) {
                    $this->applySubscriptionRefresh($refresh, $type === 'customer.subscription.created');
                }
            } else {
                $this->applyEvent($eventPayload);
            }

        });
    }

    /** Read current provider state outside transactions and retain local observations for the apply fence.
     * @param  array<string,mixed>  $payload
     * @return SubscriptionRefresh|null
     */
    private function refreshSubscription(array $payload): ?array
    {
        $data = $payload['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;
        $customer = is_array($object) ? ($object['customer'] ?? null) : null;
        $subscriptionId = is_array($object) ? ($object['id'] ?? null) : null;
        if (! is_string($customer) || $customer === '' || ! is_string($subscriptionId) || $subscriptionId === '') {
            throw new InvalidArgumentException('Subscription events require exact customer and subscription identities.');
        }
        $account = BillingModelRegistry::accountQuery()->where('stripe_id', $customer)->first();
        if ($account === null || $account->provider_deleted_at !== null) {
            return null;
        }
        $observedAccount = $account->getRawOriginal();
        $existing = BillingModelRegistry::subscriptionQuery()->where('stripe_id', $subscriptionId)->first();
        if ($existing !== null && $existing->billing_account_id !== $account->id) {
            throw BillingException::because(BillingResponseCode::ProviderIdentityMismatch, 'The subscription belongs to another billing customer.');
        }
        $observedSubscription = $this->subscriptionState($existing);
        $snapshot = null;
        foreach ($this->reader->forCustomer($customer) as $subscription) {
            if (($subscription['customer'] ?? null) !== $customer || ! is_string($subscription['id'] ?? null) || $subscription['id'] === '') {
                throw BillingException::because(BillingResponseCode::ProviderIdentityMismatch, 'Stripe returned a subscription for a different billing customer.');
            }
            if ($subscription['id'] === $subscriptionId) {
                if ($snapshot !== null) {
                    throw BillingException::because(BillingResponseCode::ProviderPayloadInvalid, 'Stripe returned duplicate subscription identities.');
                }
                $snapshot = $subscription;
            }
        }

        return compact('account', 'subscriptionId', 'observedAccount', 'observedSubscription', 'snapshot');
    }

    /** Apply only if no newer callback, reconciliation or contact write changed the observed source.
     * @param  SubscriptionRefresh  $refresh
     */
    private function applySubscriptionRefresh(array $refresh, bool $mayClearPending): void
    {
        $account = BillingModelRegistry::accountQuery()->whereKey($refresh['account']->id)->lockForUpdate()->firstOrFail();
        $subscription = BillingModelRegistry::subscriptionQuery()->where('stripe_id', $refresh['subscriptionId'])->lockForUpdate()->first();
        if ($account->getRawOriginal() !== $refresh['observedAccount']
            || $this->subscriptionState($subscription, true) !== $refresh['observedSubscription']) {
            throw BillingException::because(BillingResponseCode::ReconciliationRequired, 'Billing changed during subscription refresh; retry with current provider facts.');
        }
        if ($refresh['snapshot'] === null) {
            if ($subscription !== null) {
                $this->subscriptions->cancel($subscription, $account);
            }

            return;
        }
        $this->subscriptions->sync($refresh['snapshot']);
        $this->accounts->subscriptionSynced($account, $refresh['snapshot'], $mayClearPending);
    }

    /** Capture complete target facts without trusting an already-loaded relation.
     * @return SubscriptionState
     */
    private function subscriptionState(?BillingSubscription $subscription, bool $lock = false): array
    {
        if ($subscription === null) {
            return ['attributes' => null, 'items' => []];
        }
        $query = $subscription->items()->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $items = [];
        foreach ($query->get() as $item) {
            $items[] = $item->getRawOriginal();
        }

        return ['attributes' => $subscription->getRawOriginal(), 'items' => $items];
    }

    /**
     * Apply signed customer lifecycle facts; subscription snapshots use canonical refresh instead.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyEvent(array $payload): void
    {
        $type = $payload['type'];
        if (! is_string($type) || ! in_array($type, [
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
            $created = $payload['created'] ?? null;
            $this->customers->updateCustomer($subscription, is_int($created) && $created > 0 ? $created : null);

            return;
        }

        $this->customers->deleteCustomer($subscription);
    }
}
