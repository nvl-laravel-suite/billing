<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionItem;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Billing\Providers\BillingServiceProvider;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->cashierState = [Cashier::$registersRoutes, Cashier::$customerModel, Cashier::$subscriptionModel, Cashier::$subscriptionItemModel];
    Cashier::$registersRoutes = true;
    Cashier::$customerModel = User::class;
    Cashier::$subscriptionModel = Subscription::class;
    Cashier::$subscriptionItemModel = SubscriptionItem::class;
});

afterEach(function (): void {
    [Cashier::$registersRoutes, Cashier::$customerModel, Cashier::$subscriptionModel, Cashier::$subscriptionItemModel] = $this->cashierState;
});

it('preserves Cashier routes and models when Billing is disabled', function (): void {
    $provider = new BillingServiceProvider(app());
    $provider->register();
    $provider->boot();
    expect(Cashier::$registersRoutes)->toBeTrue()
        ->and(Cashier::$customerModel)->toBe(User::class)
        ->and(Cashier::$subscriptionModel)->toBe(Subscription::class)
        ->and(Cashier::$subscriptionItemModel)->toBe(SubscriptionItem::class);
});

it('adopts Cashier models independently of routes and NVL webhook ingress', function (): void {
    config(['nvl-billing.enabled' => true, 'nvl-tenancy.enabled' => true, 'cashier.webhook.secret' => 'whsec_test', 'nvl-billing.adoption.cashier_models' => true]);
    $provider = new BillingServiceProvider(app());
    $provider->register();
    $provider->boot();
    expect(Cashier::$customerModel)->toBe(BillingAccount::class)
        ->and(Cashier::$subscriptionModel)->toBe(BillingSubscription::class)
        ->and(Cashier::$subscriptionItemModel)->toBe(BillingSubscriptionItem::class)
        ->and(Cashier::$registersRoutes)->toBeTrue()
        ->and(app('router')->has('nvl.billing.webhook'))->toBeFalse();
});

it('suppresses Cashier routes only through explicit route adoption', function (): void {
    config(['nvl-billing.adoption.cashier_routes' => true]);
    (new BillingServiceProvider(app()))->register();
    expect(Cashier::$registersRoutes)->toBeFalse()
        ->and(Cashier::$customerModel)->toBe(User::class);
});
