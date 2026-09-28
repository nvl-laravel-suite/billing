<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

use Carbon\CarbonImmutable;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\ValueObjects\CheckoutSession;

/** Creates Stripe-hosted sessions behind a testable billing boundary. */
interface BillingGateway
{
    /** Start or recover one idempotent Checkout attempt. */
    public function checkout(
        BillingAccount $account,
        string $price,
        int $trialDays,
        string $successUrl,
        string $cancelUrl,
        CarbonImmutable $expiresAt,
        string $attemptId,
    ): CheckoutSession;

    /** Return a short-lived Customer Portal URL for an existing customer. */
    public function portal(BillingAccount $account, string $returnUrl): string;

    /** Synchronize an established Stripe customer's billing contact. */
    public function updateCustomer(BillingAccount $account, string $name, string $email): void;
}
