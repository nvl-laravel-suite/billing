<?php

declare(strict_types=1);

use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
    'models' => ['account' => BillingAccount::class, 'subscription' => BillingSubscription::class],
    'ownership' => ['scoped' => true],
    'adoption' => ['cashier_models' => false, 'cashier_routes' => false],
    'enabled' => false,
    'connection' => null,
    'migrations' => ['enabled' => false],
    'subscription_type' => 'default',
    'prices' => [],
    'access' => [
        'free' => ['features' => [], 'limits' => []],
        'plans' => [],
    ],
    'trial' => [
        'days' => 0,
        'require_payment_method' => true,
    ],
];
