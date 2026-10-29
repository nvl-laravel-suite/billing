<?php

declare(strict_types=1);

use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;

/** Common published defaults; advanced defaults remain in the package configuration. */
return ['models' => ['account' => BillingAccount::class, 'subscription' => BillingSubscription::class], 'ownership' => ['scoped' => true], 'adoption' => ['cashier_models' => false, 'cashier_routes' => false], 'enabled' => false, 'checkout' => ['return_hosts' => []], 'connection' => null, 'migrations' => ['enabled' => false]];
