<?php

declare(strict_types=1);

namespace Nvl\Billing\ValueObjects;

use Carbon\CarbonImmutable;

/** Names a hosted Checkout session returned for one tenant attempt. */
final readonly class CheckoutSession
{
    /** Store the session identity, redirect URL, and expiry. */
    public function __construct(
        public string $id,
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
