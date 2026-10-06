<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Nvl\Billing\Actions\StartCheckoutAction;
use Nvl\Billing\Actions\UpdateBillingContactAction;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Events\BillingAccountChanged;
use Nvl\Billing\Events\BillingCheckoutStarted;
use Nvl\Billing\Events\BillingSubscriptionChanged;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Services\BillingSubscriptionSyncer;
use Nvl\Billing\Testing\FakeBillingGateway;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\Models\Tenant;

it('publishes account contact changes only after commit and suppresses unchanged contacts', function (): void {
    $tenant = Tenant::factory()->create();
    $events = [];
    app('events')->listen(BillingAccountChanged::class, function (BillingAccountChanged $event) use (&$events): void {
        $events[] = $event;
    });
    $access = Mockery::mock(BillingManagementAccess::class);
    $access->shouldReceive('assertCanManage')->times(3);
    app()->instance(BillingManagementAccess::class, $access);
    $action = app(UpdateBillingContactAction::class);
    $id = new TenantId($tenant->id);
    $actor = new GenericUser(['id' => 'manager']);
    $connection = (new BillingAccount)->getConnection();
    expect($connection->transactionLevel())->toBe(0);
    $connection->beginTransaction();
    $account = $action->execute($id, $actor, 'private-name', 'private@example.test');
    expect($events)->toBe([]);
    $connection->commit();
    $action->execute($id, $actor, 'private-name', 'private@example.test');
    $action->execute($id, $actor, 'changed-private-name', 'private@example.test');
    expect(array_map(fn ($event) => $event->operation, $events))->toBe(['created', 'contact_updated'])
        ->and($events[0]->accountId)->toBe($account->id)
        ->and(serialize($events))->not->toContain('private-name', 'private@example.test');
});

it('publishes subscription and item transitions once with rollback and cancellation replay safety', function (): void {
    $tenant = Tenant::factory()->create();
    $account = app(TenantRunner::class)->run(new TenantId($tenant->id),
        fn () => BillingAccount::factory()->forTenant($tenant)->create(['stripe_id' => 'cus_c4']));
    $events = [];
    app('events')->listen(BillingSubscriptionChanged::class, function (BillingSubscriptionChanged $event) use (&$events): void {
        $events[] = $event;
    });
    $snapshot = ['id' => 'sub_c4', 'customer' => 'cus_c4', 'status' => 'active', 'items' => ['data' => [['id' => 'si_c4', 'price' => ['id' => 'price_c4', 'product' => 'prod_c4'], 'quantity' => 1]]]];
    $sync = app(BillingSubscriptionSyncer::class);
    $connection = $account->getConnection();
    $connection->beginTransaction();
    $sync->sync($snapshot);
    expect($events)->toBe([])->and($account->subscriptions()->count())->toBe(1);
    $connection->rollBack();
    expect($account->subscriptions()->count())->toBe(0)->and($events)->toBe([]);
    $sync->sync($snapshot);
    $sync->sync($snapshot);
    expect($events)->toHaveCount(1)->and($events[0]->previousStatus)->toBeNull()->and($events[0]->currentStatus)->toBe('active');
    $snapshot['items']['data'][0]['quantity'] = 2;
    $sync->sync($snapshot);
    expect($events)->toHaveCount(2);
    $subscription = $account->subscriptions()->firstOrFail();
    $sync->cancel($subscription, $account);
    $endsAt = $subscription->fresh()->ends_at;
    $sync->cancel($subscription->fresh(), $account);
    expect($events)->toHaveCount(3)->and($subscription->fresh()->ends_at->equalTo($endsAt))->toBeTrue();
});

it('publishes the first persisted checkout session and reuses it without another fact or Stripe call', function (): void {
    $tenant = Tenant::factory()->create();
    $access = Mockery::mock(BillingManagementAccess::class);
    $access->shouldReceive('assertCanManage')->twice();
    app()->instance(BillingManagementAccess::class, $access);
    config(['nvl-billing.prices' => ['pro' => ['month' => 'price_c4']]]);
    $session = new CheckoutSession('cs_c4', 'https://checkout.stripe.com/private-session', CarbonImmutable::now()->addHour());
    $gateway = FakeBillingGateway::fake(app())->willReturn('checkout', $session);
    $events = [];
    app('events')->listen(BillingCheckoutStarted::class, function (BillingCheckoutStarted $event) use (&$events): void {
        expect(BillingAccount::query()->findOrFail($event->accountId)->pending_checkout_session_id)->toBe($event->sessionId);
        $events[] = $event;
    });
    $action = app(StartCheckoutAction::class);
    $arguments = [new TenantId($tenant->id), new GenericUser(['id' => 'manager']), 'private@example.test', 'pro', 'month', 'https://example.test/success', 'https://example.test/cancel'];
    $action->execute(...$arguments);
    $action->execute(...$arguments);
    expect($events)->toHaveCount(1)->and($events[0]->sessionId)->toBe('cs_c4')
        ->and(serialize($events[0]))->not->toContain('private@example.test', 'private-session');
    $gateway->assertCalled('checkout');
});
