<?php

declare(strict_types=1);

namespace Nvl\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Cashier\SubscriptionItem;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\Concerns\UsesBillingConnection;

/**
 * Stores Stripe Price ownership for a Billing subscription.
 *
 * @property string $id
 * @property string $billing_subscription_id
 * @property string $stripe_id
 * @property string $stripe_product
 * @property string $stripe_price
 * @property int|null $quantity
 */
final class BillingSubscriptionItem extends SubscriptionItem
{
    use HasUuids;
    use UsesBillingConnection;

    public const string TABLE = BillingTables::SubscriptionItems;

    protected $table = self::TABLE;
}
