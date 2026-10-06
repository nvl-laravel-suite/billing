<?php

declare(strict_types=1);

namespace Nvl\Billing\Events;

use Nvl\Support\Contracts\DomainEvent;

/** A committed Billing identity snapshot without contact or provider payload data.
 *
 * @api
 */
final readonly class BillingCheckoutStarted implements DomainEvent
{
    /** Capture the persisted lifecycle fact. */
    public function __construct(public string $accountId, public string $tenantId, public string $attemptId, public string $sessionId, public int $schemaVersion = 1) {}

    /** Return the immutable payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
