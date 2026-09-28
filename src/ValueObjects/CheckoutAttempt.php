<?php

declare(strict_types=1);

namespace Nvl\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use Nvl\Billing\Models\BillingAccount;

/** Holds the durable attempt reserved before contacting Stripe. */
final readonly class CheckoutAttempt
{
    /** Preserve a reserved attempt and any already-created hosted session. */
    public function __construct(
        public BillingAccount $account,
        public string $id,
        public string $price,
        public int $trialDays,
        public CarbonImmutable $expiresAt,
        public ?CheckoutSession $session,
    ) {}
}
