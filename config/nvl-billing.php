<?php

declare(strict_types=1);

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
    'adoption' => ['cashier_models' => false, 'cashier_routes' => false],
    'enabled' => false,
    'routes' => ['webhook' => ['enabled' => false]],
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
