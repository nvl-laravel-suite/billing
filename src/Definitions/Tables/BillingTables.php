<?php

declare(strict_types=1);

namespace Nvl\Billing\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/** Names the tables owned by the optional Billing package. */
final class BillingTables
{
    public const string Accounts = 'nvl_billing_accounts';

    public const string Subscriptions = 'nvl_billing_subscriptions';

    public const string SubscriptionItems = 'nvl_billing_subscription_items';

    public const string WebhookEvents = 'nvl_billing_webhook_events';

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('billing', $key);
    }
}
