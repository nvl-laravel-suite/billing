<?php

declare(strict_types=1);

namespace Nvl\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription;
use Nvl\Billing\Database\Factories\BillingSubscriptionFactory;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\Concerns\UsesBillingConnection;
use Nvl\Support\Config\PackageStorage;

/**
 * Stores Cashier subscription state under Billing's own table name.
 *
 * @property string $id
 * @property string $billing_account_id
 * @property string $type
 * @property string $stripe_id
 * @property Carbon|null $created_at
 * @property string $stripe_status
 * @property string|null $stripe_price
 * @property Carbon|null $ends_at
 *
 * @api
 *
 * @nvl-consumer-read id
 */
final class BillingSubscription extends Subscription
{
    /** @use HasFactory<BillingSubscriptionFactory> */
    use HasFactory;

    use HasUuids;
    use UsesBillingConnection;

    public const string TABLE = BillingTables::Subscriptions;

    protected $table = self::TABLE;

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return BillingTables::get(BillingTables::Subscriptions);
    }

    /** Resolve the package connection through shared infrastructure defaults. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('billing') ?? parent::getConnectionName());
    }

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): BillingSubscriptionFactory
    {
        return BillingSubscriptionFactory::new();
    }
}
