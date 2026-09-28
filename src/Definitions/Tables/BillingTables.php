<?php

declare(strict_types=1);

namespace Nvl\Billing\Definitions\Tables;

/** Names the tables owned by the optional Billing package. */
final class BillingTables
{
    public const string Accounts = 'nvl_billing_accounts';

    public const string Subscriptions = 'nvl_billing_subscriptions';

    public const string SubscriptionItems = 'nvl_billing_subscription_items';

    public const string WebhookEvents = 'nvl_billing_webhook_events';
}
