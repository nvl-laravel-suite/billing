<?php

declare(strict_types=1);

return [
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
