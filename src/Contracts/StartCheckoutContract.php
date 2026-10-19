<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Starts an authorized owner's hosted subscription Checkout.
 *
 * @api
 */
interface StartCheckoutContract
{
    /**
     * Start or return the existing Checkout for one owner and Price.
     *
     * @throws DomainException When the tenant has a subscription or a different pending Price
     * @throws InvalidArgumentException When checkout input is invalid
     */
    public function execute(
        OwnerReference $owner,
        Authenticatable $actor,
        string $billingEmail,
        string $plan,
        string $interval,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSession;
}
