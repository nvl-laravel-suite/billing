<?php

declare(strict_types=1);

namespace Nvl\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription;
use Nvl\Billing\Database\Factories\BillingSubscriptionFactory;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\Concerns\UsesBillingConnection;
use Nvl\Billing\Services\BillingModelRegistry;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

/**
 * Stores Cashier subscription state under Billing's own table name.
 *
 * @property string $id
 * @property string $billing_account_id
 * @property string $type
 * @property string $stripe_id
 * @property Carbon|null $created_at
 * @property string $stripe_status
 * @property int|null $quantity
 * @property string|null $stripe_price
 * @property Carbon|null $ends_at
 *
 * @api
 *
 * @nvl-consumer-host-subclasses
 *
 * @nvl-consumer-read id
 */
class BillingSubscription extends Subscription implements Ownable
{
    /** @use HasFactory<BillingSubscriptionFactory> */
    use HasFactory;

    use HasOwnership;
    use HasUuids;
    use UsesBillingConnection;

    public const string TABLE = BillingTables::Subscriptions;

    protected $table = self::TABLE;

    /** Resolve the canonical inherited billing parent.
     * @return BelongsTo<BillingAccount, $this>
     */
    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingModelRegistry::accountClass(), 'billing_account_id');
    }

    /** Retain Cashier's owner relation on the native configured account.
     * @return BelongsTo<BillingAccount, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->billingAccount();
    }

    /** Technical item rows retain their native class and fixed parent key.
     * @return HasMany<BillingSubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BillingSubscriptionItem::class, 'billing_subscription_id');
    }

    /** Preserve native relation keys when a deployment selects a subclass. */
    public function getForeignKey(): string
    {
        return 'billing_subscription_id';
    }

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
