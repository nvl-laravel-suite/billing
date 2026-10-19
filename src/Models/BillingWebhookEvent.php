<?php

declare(strict_types=1);

namespace Nvl\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\Concerns\HasBillingOwnership;
use Nvl\Billing\Models\Concerns\UsesBillingConnection;
use Nvl\Support\Ownership\Contracts\Ownable;

/**
 * Preserves a verified provider receipt without retaining remote payload content.
 *
 * @property string $stripe_event_id
 * @property string $type
 * @property string $processed_at
 * @property string $owner_scope_key
 * @property string|null $owner_reference
 *
 * @internal
 */
final class BillingWebhookEvent extends Model implements Ownable
{
    use HasBillingOwnership;
    use UsesBillingConnection;

    protected $primaryKey = 'stripe_event_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** Resolve the package-owned actual receipt source. */
    public function getTable(): string
    {
        return BillingTables::get(BillingTables::WebhookEvents);
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [];
    }
}
