<?php

declare(strict_types=1);

namespace Nvl\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\Concerns\UsesBillingConnection;
use Nvl\Support\Config\PackageStorage;

/**
 * Represents one tenant's Stripe customer without coupling billing to a user.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $name
 * @property string $email
 * @property string|null $stripe_id
 * @property Carbon|null $trial_consumed_at
 * @property string|null $pending_checkout_attempt_id
 * @property string|null $pending_checkout_price
 * @property string|null $pending_checkout_session_id
 * @property string|null $pending_checkout_url
 * @property CarbonImmutable|null $pending_checkout_expires_at
 * @property int|null $pending_checkout_trial_days
 *
 * @api
 */
final class BillingAccount extends Model
{
    use Billable;
    use HasUuids;
    use UsesBillingConnection;

    public const string TABLE = BillingTables::Accounts;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'email'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trial_consumed_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'pending_checkout_expires_at' => 'immutable_datetime',
            'pending_checkout_trial_days' => 'integer',
        ];
    }

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return BillingTables::get(BillingTables::Accounts);
    }

    /** Resolve the package connection through shared infrastructure defaults. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('billing') ?? parent::getConnectionName());
    }
}
