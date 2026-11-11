<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Billing\Services\BillingAccess;
use Nvl\Billing\Services\BillingWebhooks;

/** Script the canonical provider read independently from incoming signed bytes.
 * @param  array<string,mixed>  $subscription
 */
function billingCurrentSubscription(array $subscription, int $reads = 1): void
{
    $reader = Mockery::mock(SubscriptionReader::class);
    $reader->shouldReceive('forCustomer')->with($subscription['customer'])->times($reads)->andReturn([$subscription]);
    app()->instance(SubscriptionReader::class, $reader);
}

/** Submit a real correctly signed envelope to the native boundary.
 * @param  array<string,mixed>  $subscription
 */
function billingSignedSubscription(string $eventId, string $type, array $subscription, ?int $created = null): void
{
    $payload = json_encode(['id' => $eventId, 'type' => $type, 'created' => $created ?? time(), 'data' => ['object' => $subscription]], JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');
    app(BillingWebhooks::class)->process($payload, "t={$timestamp},v1={$signature}");
}

it('rejects signed deliveries while billing is disabled without recording receipts', function (): void {
    billingNativeRun(function (): void {
        config()->set('nvl-billing.enabled', false);
        expect(fn () => billingSignedSubscription('evt_disabled', 'customer.updated', ['id' => 'cus_disabled']))
            ->toThrow(BillingException::class, 'Billing is disabled');
        expect(DB::table(BillingTables::WebhookEvents)->count())->toBe(0);
    });
});

it('requires a signed webhook and applies each Stripe event once', function (): void {
    billingNativeRun(function () {
        $account = BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_billing_test', 'pending_checkout_attempt_id' => 'a3922877-192b-436e-a562-e97b3a719152', 'pending_checkout_price' => 'price_pro_month']);
        $event = ['id' => 'evt_billing_test', 'type' => 'customer.subscription.created', 'data' => ['object' => ['id' => 'sub_billing_test', 'customer' => 'cus_billing_test', 'status' => 'trialing', 'trial_end' => now()->addDays(14)->timestamp, 'metadata' => ['type' => 'default'], 'items' => ['data' => [['id' => 'si_billing_test', 'price' => ['id' => 'price_pro_month', 'product' => 'prod_pro'], 'quantity' => 1]]]]]];
        billingCurrentSubscription($event['data']['object']);
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}"];
        $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertForbidden();
        $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], $server, $payload)->assertSuccessful();
        $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], $server, $payload)->assertSuccessful();
        expect($account->fresh()->trial_consumed_at)->not->toBeNull()->and($account->fresh()->pending_checkout_attempt_id)->toBeNull()->and($account->subscriptions()->count())->toBe(1)->and(DB::table(BillingTables::WebhookEvents)->count())->toBe(1);
    });
});
it('rejects malformed signed billing envelopes before recording or applying them', function (string $payload): void {
    billingNativeRun(function () use ($payload) {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');
        $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}"], $payload)->assertBadRequest();
        expect(DB::table(BillingTables::WebhookEvents)->count())->toBe(0);
    });
})->with(['{', 'null', '{"id":"","type":"customer.updated","data":{"object":{"id":"cus_test"}}}', '{"id":"not_an_event","type":"customer.updated","data":{"object":{"id":"cus_test"}}}', '{"id":"evt_bad","type":"customer.updated","data":{"object":null}}', '{"id":"evt_bad","type":"customer.updated","data":{"object":{"id":[]}}}', '{"id":"evt_bad","type":"","data":{"object":{"id":"cus_test"}}}']);
it('records a completed trial from a later active subscription event', function (): void {
    billingNativeRun(function () {
        $account = BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_completed_trial']);
        $event = ['id' => 'evt_completed_trial', 'type' => 'customer.subscription.created', 'data' => ['object' => ['id' => 'sub_completed_trial', 'customer' => 'cus_completed_trial', 'status' => 'active', 'trial_start' => now()->subDays(14)->timestamp, 'trial_end' => now()->subMinute()->timestamp, 'metadata' => ['type' => 'default'], 'items' => ['data' => [['id' => 'si_completed_trial', 'price' => ['id' => 'price_pro_month', 'product' => 'prod_pro'], 'quantity' => 1]]]]]];
        billingCurrentSubscription($event['data']['object']);
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');
        $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}"], $payload)->assertSuccessful();
        expect($account->fresh()->trial_consumed_at)->not->toBeNull();
    });
});
it('syncs a scheduled cancellation from the canonical provider reader', function (): void {
    billingNativeRun(function () {
        $account = BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_cancel_test']);
        $periodEnd = now()->addDays(12)->timestamp;
        $event = ['id' => 'evt_cancel_test', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_cancel_test', 'customer' => 'cus_cancel_test', 'status' => 'active', 'cancel_at_period_end' => true, 'metadata' => ['type' => 'default'], 'items' => ['data' => [['id' => 'si_cancel_test', 'price' => ['id' => 'price_pro_month', 'product' => 'prod_pro'], 'quantity' => 1, 'current_period_end' => $periodEnd]]]]]];
        billingCurrentSubscription($event['data']['object']);
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');
        $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}"], $payload)->assertSuccessful();
        expect($account->subscriptions()->first()->ends_at?->timestamp)->toBe($periodEnd);
    });
});
it('stores provider timestamps as the same instant when the application timezone is not UTC', function (): void {
    $timezone = date_default_timezone_get();
    config(['app.timezone' => 'Europe/Sofia']);
    date_default_timezone_set('Europe/Sofia');

    try {
        billingNativeRun(function (): void {
            $account = BillingAccount::query()->forceCreate(['email' => 'zone@example.test', 'stripe_id' => 'cus_zone']);
            $cancelAt = now()->addHour()->timestamp;
            $trialEnd = now()->addMinutes(30)->timestamp;
            $current = ['id' => 'sub_zone', 'customer' => 'cus_zone', 'status' => 'trialing', 'cancel_at' => $cancelAt, 'trial_end' => $trialEnd, 'metadata' => ['type' => 'default'], 'items' => ['data' => [['id' => 'si_zone', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1]]]];
            billingCurrentSubscription($current);
            billingSignedSubscription('evt_zone', 'customer.subscription.updated', $current);
            $subscription = $account->subscriptions()->sole();

            expect($subscription->ends_at?->timestamp)->toBe($cancelAt)
                ->and($subscription->trial_ends_at?->timestamp)->toBe($trialEnd)
                ->and(app(BillingAccess::class)->forOwner(billingNativeOwner(7))['plan'])->toBe('pro');
        });
    } finally {
        date_default_timezone_set($timezone);
        config(['app.timezone' => $timezone]);
    }
});
it('synchronizes an explicit billing contact changed through the Stripe portal', function (): void {
    billingNativeRun(function () {
        $account = BillingAccount::query()->forceCreate(['email' => 'old@example.test', 'stripe_id' => 'cus_contact_test']);
        $payload = json_encode(['id' => 'evt_contact_test', 'type' => 'customer.updated', 'data' => ['object' => ['id' => 'cus_contact_test', 'name' => 'Acme Billing', 'email' => 'new@example.test']]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');
        $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}"], $payload)->assertSuccessful();
        expect($account->fresh()->name)->toBe('Acme Billing')->and($account->fresh()->email)->toBe('new@example.test');
    });
});
it('revokes subscription access when Stripe deletes the billing customer', function (): void {
    billingNativeRun(function () {
        $account = BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_deleted']);
        $subscription = BillingSubscription::factory()->forAccount($account)->create(['type' => 'default', 'stripe_id' => 'sub_deleted_customer', 'stripe_status' => 'active']);
        $payload = json_encode(['id' => 'evt_customer_deleted', 'type' => 'customer.deleted', 'data' => ['object' => ['id' => 'cus_deleted']]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_billing_test');
        $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe_Signature' => "t={$timestamp},v1={$signature}"], $payload)->assertNoContent();
        expect($account->fresh()->stripe_id)->toBe('cus_deleted')->and($account->fresh()->provider_deleted_at)->not->toBeNull()->and($subscription->fresh()->stripe_status)->toBe('canceled')->and($subscription->fresh()->ends_at)->not->toBeNull();
    });
});

it('rejects an invalid webhook tolerance before verifying a provider payload', function (): void {
    config(['cashier.webhook.secret' => 'whsec_billing_test', 'cashier.webhook.tolerance' => 'unsafe']);

    expect(fn () => app(BillingWebhooks::class)->process('{}', 'signature'))
        ->toThrow(InvalidArgumentException::class, 'tolerance');
});

it('refreshes distinct stale subscription events without restoring canceled access or refetching exact replays', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'audit@example.test', 'stripe_id' => 'cus_stale']);
        $current = ['id' => 'sub_stale', 'customer' => 'cus_stale', 'status' => 'canceled', 'ended_at' => time() - 10, 'metadata' => ['type' => 'default'], 'items' => ['data' => [['id' => 'si_stale', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1]]]];
        billingCurrentSubscription($current, 2);
        billingSignedSubscription('evt_new_cancel', 'customer.subscription.deleted', $current);
        expect(app(BillingAccess::class)->forOwner(billingNativeOwner(7))['plan'])->toBeNull()
            ->and($account->subscriptions()->sole()->stripe_status)->toBe('canceled');

        $stale = $current;
        $stale['status'] = 'active';
        unset($stale['ended_at']);
        billingSignedSubscription('evt_old_active', 'customer.subscription.updated', $stale, time() - 60);
        billingSignedSubscription('evt_old_active', 'customer.subscription.updated', $stale, time() - 60);

        expect(app(BillingAccess::class)->forOwner(billingNativeOwner(7))['plan'])->toBeNull()
            ->and($account->subscriptions()->sole()->stripe_status)->toBe('canceled')
            ->and(DB::table(BillingTables::get(BillingTables::WebhookEvents))->count())->toBe(2);
    });
});

it('uses current provider facts instead of stale signed prices and status', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'audit@example.test', 'stripe_id' => 'cus_current']);
        $current = ['id' => 'sub_current', 'customer' => 'cus_current', 'status' => 'active', 'metadata' => ['type' => 'default'], 'items' => ['data' => [['id' => 'si_current', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1]]]];
        billingCurrentSubscription($current);
        $stale = $current;
        $stale['status'] = 'past_due';
        $stale['items']['data'][0]['price']['id'] = 'price_retired';
        billingSignedSubscription('evt_stale_price', 'customer.subscription.updated', $stale);
        expect($account->subscriptions()->sole()->stripe_status)->toBe('active')
            ->and($account->subscriptions()->sole()->stripe_price)->toBe('price_pro')
            ->and(app(BillingAccess::class)->forOwner(billingNativeOwner(7))['plan'])->toBe('pro');
    });
});

it('cancels a stored subscription absent from the complete current provider listing', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'audit@example.test', 'stripe_id' => 'cus_missing']);
        $subscription = BillingSubscription::factory()->forAccount($account)->create(['stripe_id' => 'sub_missing', 'stripe_status' => 'active']);
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldReceive('forCustomer')->once()->with('cus_missing')->andReturn([]);
        app()->instance(SubscriptionReader::class, $reader);
        billingSignedSubscription('evt_missing', 'customer.subscription.updated', ['id' => 'sub_missing', 'customer' => 'cus_missing', 'status' => 'active']);
        expect($subscription->fresh()->stripe_status)->toBe('canceled')
            ->and($subscription->fresh()->ends_at)->not->toBeNull();
    });
});

it('retains retryability when the canonical subscription refresh fails', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'audit@example.test', 'stripe_id' => 'cus_retry']);
        $current = ['id' => 'sub_retry', 'customer' => 'cus_retry', 'status' => 'active', 'items' => ['data' => [['id' => 'si_retry', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1]]]];
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldReceive('forCustomer')->once()->with('cus_retry')->andThrow(new RuntimeException('Provider timeout'));
        $reader->shouldReceive('forCustomer')->once()->with('cus_retry')->andReturn([$current]);
        app()->instance(SubscriptionReader::class, $reader);
        expect(fn () => billingSignedSubscription('evt_retry', 'customer.subscription.updated', $current))->toThrow(RuntimeException::class, 'Provider timeout');
        expect(DB::table(BillingTables::get(BillingTables::WebhookEvents))->count())->toBe(0)
            ->and($account->subscriptions()->count())->toBe(0);
        billingSignedSubscription('evt_retry', 'customer.subscription.updated', $current);
        expect($account->subscriptions()->sole()->stripe_status)->toBe('active');
    });
});

it('refuses subscription refresh inside an outer transaction before provider reads or receipts', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'audit@example.test', 'stripe_id' => 'cus_outer_webhook']);
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldNotReceive('forCustomer');
        app()->instance(SubscriptionReader::class, $reader);
        $connection = $account->getConnection();
        $connection->beginTransaction();
        try {
            expect(fn () => billingSignedSubscription('evt_outer_webhook', 'customer.subscription.updated', ['id' => 'sub_outer', 'customer' => 'cus_outer_webhook']))
                ->toThrow(DomainException::class, 'outside a database transaction');
            expect(DB::table(BillingTables::get(BillingTables::WebhookEvents))->count())->toBe(0);
        } finally {
            $connection->rollBack();
        }
    });
});

it('rejects a foreign provider customer without recording its triggering receipt', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'audit@example.test', 'stripe_id' => 'cus_exact']);
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldReceive('forCustomer')->once()->with('cus_exact')->andReturn([['id' => 'sub_exact', 'customer' => 'cus_foreign']]);
        app()->instance(SubscriptionReader::class, $reader);
        expect(fn () => billingSignedSubscription('evt_foreign', 'customer.subscription.updated', ['id' => 'sub_exact', 'customer' => 'cus_exact']))
            ->toThrow(BillingException::class, 'different billing customer');
        expect(DB::table(BillingTables::get(BillingTables::WebhookEvents))->count())->toBe(0)
            ->and($account->subscriptions()->count())->toBe(0);
    });
});

it('does not overwrite newer local observations made during the canonical provider read', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'audit@example.test', 'stripe_id' => 'cus_race']);
        $subscription = BillingSubscription::factory()->forAccount($account)->create(['stripe_id' => 'sub_race', 'stripe_status' => 'past_due']);
        $active = ['id' => 'sub_race', 'customer' => 'cus_race', 'status' => 'active', 'items' => ['data' => [['id' => 'si_race', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1]]]];
        $reads = 0;
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldReceive('forCustomer')->times(3)->with('cus_race')->andReturnUsing(function () use ($subscription, $active, &$reads): array {
            expect($subscription->getConnection()->transactionLevel())->toBe(0);
            $subscription->update(['stripe_status' => 'canceled', 'ends_at' => now()->addSeconds(++$reads)]);

            return [$active];
        });
        app()->instance(SubscriptionReader::class, $reader);
        expect(fn () => billingSignedSubscription('evt_race', 'customer.subscription.updated', $active))
            ->toThrow(BillingException::class, 'changed during subscription refresh');
        expect($subscription->fresh()->stripe_status)->toBe('canceled')
            ->and(DB::table(BillingTables::get(BillingTables::WebhookEvents))->count())->toBe(0);
    });
});

it('rolls subscription state and its receipt back together when canonical persistence fails', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'audit@example.test', 'stripe_id' => 'cus_persist_retry']);
        $current = ['id' => 'sub_persist_retry', 'customer' => 'cus_persist_retry', 'status' => 'active', 'items' => ['data' => [['id' => 'si_persist_retry', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1]]]];
        billingCurrentSubscription($current, 2);
        $fail = true;
        BillingSubscriptionItem::creating(static function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Canonical item failure');
            }
        });
        expect(fn () => billingSignedSubscription('evt_persist_retry', 'customer.subscription.updated', $current))
            ->toThrow(RuntimeException::class, 'Canonical item failure');
        expect(DB::table(BillingTables::get(BillingTables::WebhookEvents))->count())->toBe(0)
            ->and($account->subscriptions()->count())->toBe(0);
        $fail = false;
        billingSignedSubscription('evt_persist_retry', 'customer.subscription.updated', $current);
        expect($account->subscriptions()->sole()->stripe_status)->toBe('active')
            ->and(DB::table(BillingTables::get(BillingTables::WebhookEvents))->count())->toBe(1);
    });
});

it('ignores a late customer update older than the last applied one', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['name' => 'Original', 'email' => 'original@example.test', 'stripe_id' => 'cus_ordered']);
        $newer = time() - 10;
        billingSignedSubscription('evt_customer_newer', 'customer.updated', ['id' => 'cus_ordered', 'name' => 'Newer', 'email' => 'newer@example.test'], $newer);
        billingSignedSubscription('evt_customer_older', 'customer.updated', ['id' => 'cus_ordered', 'name' => 'Older', 'email' => 'older@example.test'], $newer - 50);

        expect($account->fresh()->name)->toBe('Newer')
            ->and($account->fresh()->email)->toBe('newer@example.test')
            ->and($account->fresh()->provider_customer_updated_at?->getTimestamp())->toBe($newer)
            ->and(DB::table(BillingTables::get(BillingTables::WebhookEvents))->count())->toBe(2);

        billingSignedSubscription('evt_customer_same_second', 'customer.updated', ['id' => 'cus_ordered', 'name' => 'Same second', 'email' => 'newer@example.test'], $newer);
        billingSignedSubscription('evt_customer_latest', 'customer.updated', ['id' => 'cus_ordered', 'name' => 'Latest', 'email' => 'latest@example.test'], $newer + 5);

        expect($account->fresh()->name)->toBe('Latest')
            ->and($account->fresh()->email)->toBe('latest@example.test')
            ->and($account->fresh()->provider_customer_updated_at?->getTimestamp())->toBe($newer + 5);
    });
});

it('waits for a concurrent subscription callback and applies current provider facts instead of refusing', function (): void {
    billingNativeRun(function (): void {
        $account = BillingAccount::query()->forceCreate(['email' => 'race@example.test', 'stripe_id' => 'cus_race']);
        $current = ['id' => 'sub_race', 'customer' => 'cus_race', 'status' => 'active', 'metadata' => ['type' => 'default'], 'items' => ['data' => [['id' => 'si_race', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1]]]];
        billingCurrentSubscription($current);
        billingSignedSubscription('evt_race_created', 'customer.subscription.created', $current);
        $reads = 0;
        $reader = Mockery::mock(SubscriptionReader::class);
        $reader->shouldReceive('forCustomer')->with('cus_race')->andReturnUsing(function () use (&$reads, $current): array {
            if (++$reads === 1) {
                DB::table(BillingTables::get(BillingTables::Subscriptions))->where('stripe_id', 'sub_race')->update(['stripe_status' => 'past_due']);
            }

            return [[...$current, 'status' => 'trialing']];
        });
        app()->instance(SubscriptionReader::class, $reader);
        app()->forgetInstance(BillingWebhooks::class);

        billingSignedSubscription('evt_race_updated', 'customer.subscription.updated', $current);

        expect($reads)->toBe(2)
            ->and($account->subscriptions()->sole()->stripe_status)->toBe('trialing')
            ->and(DB::table(BillingTables::get(BillingTables::WebhookEvents))->where('stripe_event_id', 'evt_race_updated')->count())->toBe(1);
    });
});
