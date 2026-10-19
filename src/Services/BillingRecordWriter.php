<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Nvl\Billing\Compatibility\LegacyBillingModelScope;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;

/** Persists Cashier children through their canonical native parent assignment. @internal */
final readonly class BillingRecordWriter
{
    /** Retain only the neutral write boundary. */
    public function __construct(private OwnershipWriter $writer) {}

    /** Save the exact subscription created by the verified package synchronization workflow. */
    public function subscription(BillingSubscription $record): void
    {
        if ($record->exists || LegacyBillingModelScope::permitsLegacy($record)) {
            $record->save();

            return;
        }
        $this->writer->create(BillingSubscription::class, static function (array $ownership) use ($record): BillingSubscription {
            $record->forceFill($ownership)->save();

            return $record;
        });
    }

    /** Save the exact item created by the verified package synchronization workflow. */
    public function item(BillingSubscriptionItem $record): void
    {
        if ($record->exists || LegacyBillingModelScope::permitsLegacy($record)) {
            $record->save();

            return;
        }
        $this->writer->create(BillingSubscriptionItem::class, static function (array $ownership) use ($record): BillingSubscriptionItem {
            $record->forceFill($ownership)->save();

            return $record;
        });
    }
}
