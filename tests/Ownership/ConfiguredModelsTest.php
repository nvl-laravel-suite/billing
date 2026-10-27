<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Cashier\Cashier;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Services\BillingContacts;
use Nvl\Billing\Services\BillingModelRegistry;
use Nvl\Billing\Services\BillingSubscriptionSyncer;
use Nvl\Billing\Testing\FakeBillingGateway;
use Nvl\Billing\Tests\BillingTestCase;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

class ConfiguredBillingAccountFixture extends BillingAccount {}
class IncompatibleBillingAccountCasts extends BillingAccount
{
    protected function casts(): array
    {
        return [...parent::casts(), 'pending_checkout_trial_days' => 'string'];
    }
}
class IncompatibleBillingSubscriptionHook extends BillingSubscription
{
    public function newModelQuery(): Builder
    {
        return parent::newModelQuery();
    }
}
class SplitBillingSubscriptionSource extends BillingSubscription
{
    protected $connection = 'financial_other';
}

class ConfiguredBillingSubscriptionFixture extends BillingSubscription {}

class ConfiguredBillingModelsTestCase extends BillingTestCase
{
    protected function setUp(): void
    {
        Relation::morphMap([], false);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Relation::morphMap([], false);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set([
            'nvl-billing.models.account' => ConfiguredBillingAccountFixture::class,
            'nvl-billing.models.subscription' => ConfiguredBillingSubscriptionFixture::class,
        ]);
    }
}

uses(ConfiguredBillingModelsTestCase::class);

it('uses configured financial roots through services Cashier child relations and rollback', function (): void {
    billingNativeRun(function (): void {
        $access = Mockery::mock(BillingManagementAccess::class);
        $access->shouldReceive('assertCanManage');
        app()->instance(BillingManagementAccess::class, $access);
        FakeBillingGateway::fake(app());
        $account = app(BillingContacts::class)->update(billingNativeOwner(7), Actor::findOrFail(1), 'Billing', 'billing@example.test');
        expect($account)->toBeInstanceOf(ConfiguredBillingAccountFixture::class)
            ->and(Cashier::$customerModel)->toBe(ConfiguredBillingAccountFixture::class)
            ->and(Cashier::$subscriptionModel)->toBe(ConfiguredBillingSubscriptionFixture::class)
            ->and(app(OwnershipResourceRegistry::class)->get('billing.accounts')->model)->toBe(ConfiguredBillingAccountFixture::class)
            ->and(app(OwnershipResourceRegistry::class)->get('billing.subscriptions')->model)->toBe(ConfiguredBillingSubscriptionFixture::class)
            ->and(Relation::getMorphedModel(BillingAccount::class))->toBe(ConfiguredBillingAccountFixture::class);
        $account->forceFill(['stripe_id' => 'cus_configured'])->save();
        $snapshot = ['id' => 'sub_configured', 'customer' => 'cus_configured', 'status' => 'active', 'items' => ['data' => [['id' => 'si_configured', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1]]]];
        $connection = $account->getConnection();
        $connection->beginTransaction();
        app(BillingSubscriptionSyncer::class)->sync($snapshot);
        $subscription = $account->subscriptions()->firstOrFail();
        expect($subscription)->toBeInstanceOf(ConfiguredBillingSubscriptionFixture::class)
            ->and($subscription->billingAccount)->toBeInstanceOf(ConfiguredBillingAccountFixture::class)
            ->and($subscription->owner)->toBeInstanceOf(ConfiguredBillingAccountFixture::class)
            ->and($subscription->items->sole()->billingSubscription)->toBeInstanceOf(ConfiguredBillingSubscriptionFixture::class)
            ->and($subscription->items->sole()->subscription)->toBeInstanceOf(ConfiguredBillingSubscriptionFixture::class);
        $restored = $subscription->newQueryForRestoration($subscription->id)->firstOrFail();
        expect($restored)->toBeInstanceOf(ConfiguredBillingSubscriptionFixture::class)
            ->and($restored->getKey())->toBe($subscription->id);
        $connection->rollBack();
        expect($account->subscriptions()->count())->toBe(0);
        app(BillingSubscriptionSyncer::class)->sync($snapshot);
        expect($account->subscriptions()->sole())->toBeInstanceOf(ConfiguredBillingSubscriptionFixture::class);
    });
});

it('uses configured Billing factories and preserves native relations without global Cashier adoption', function (): void {
    billingNativeRun(function (): void {
        $customerModel = Cashier::$customerModel;
        $subscriptionModel = Cashier::$subscriptionModel;
        Cashier::useCustomerModel(BillingAccount::class);
        Cashier::useSubscriptionModel(BillingSubscription::class);
        try {
            $subscription = BillingSubscription::factory()->create();
            expect($subscription)->toBeInstanceOf(ConfiguredBillingSubscriptionFixture::class)
                ->and($subscription->owner)->toBeInstanceOf(ConfiguredBillingAccountFixture::class)
                ->and($subscription->owner->subscriptions->sole())->toBeInstanceOf(ConfiguredBillingSubscriptionFixture::class)
                ->and($subscription->getForeignKey())->toBe('billing_subscription_id');
        } finally {
            Cashier::useCustomerModel($customerModel);
            Cashier::useSubscriptionModel($subscriptionModel);
        }
    });
});

it('rejects incompatible Billing extensions before native persistence', function (string $root, string $class, string $message): void {
    $map = Relation::morphMap();
    Relation::morphMap([], false);
    config(['database.connections.financial_other' => config('database.connections.'.config('database.default'))]);
    try {
        $configuration = new Repository(['nvl-billing' => ['models' => [$root => $class]]]);
        $registry = new BillingModelRegistry($configuration, new OwnerRegistry($configuration));
        expect(fn () => $root === 'account' ? $registry->accountModel() : $registry->subscriptionModel())
            ->toThrow(OwnershipConfigurationInvalid::class, $message);
    } finally {
        Relation::morphMap($map, false);
    }
})->with([
    'unrelated class' => ['account', stdClass::class, 'concrete subclasses'],
    'required cast' => ['account', IncompatibleBillingAccountCasts::class, 'required package casts'],
    'query hook' => ['subscription', IncompatibleBillingSubscriptionHook::class, 'canonical query and persistence hooks'],
    'split child source' => ['subscription', SplitBillingSubscriptionSource::class, 'package table, actual connection'],
]);
