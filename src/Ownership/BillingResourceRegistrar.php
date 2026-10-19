<?php

declare(strict_types=1);

namespace Nvl\Billing\Ownership;

use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Billing\Models\BillingWebhookEvent;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/** Declares one captured account with inherited Cashier state and independently durable provider receipts. @internal */
final readonly class BillingResourceRegistrar
{
    /** Register exact package-owned persistence independently from schema installation. */
    public function register(OwnershipResourceRegistry $resources, bool $owned): void
    {
        $resources->register(new OwnershipResourceDefinition('billing.accounts', 'billing', BillingAccount::class,
            $owned ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped()));
        $resources->register(new OwnershipResourceDefinition('billing.subscriptions', 'billing', BillingSubscription::class,
            $owned ? OwnershipDefinition::inherited('billingAccount') : OwnershipDefinition::unscoped(), parentResource: $owned ? 'billing.accounts' : null,
            immutableFields: $owned ? ['stripe_id'] : []));
        $resources->register(new OwnershipResourceDefinition('billing.items', 'billing', BillingSubscriptionItem::class,
            $owned ? OwnershipDefinition::inherited('billingSubscription') : OwnershipDefinition::unscoped(), parentResource: $owned ? 'billing.subscriptions' : null,
            immutableFields: $owned ? ['stripe_id'] : []));
        $resources->register(new OwnershipResourceDefinition('billing.webhooks', 'billing', BillingWebhookEvent::class,
            $owned ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped(), allowsCentral: $owned,
            immutableFields: $owned ? ['type', 'processed_at'] : []));
    }
}
