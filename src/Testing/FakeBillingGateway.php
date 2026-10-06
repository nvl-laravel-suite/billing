<?php

declare(strict_types=1);

namespace Nvl\Billing\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Support\Testing\FakeCalls;
use TypeError;

/**
 * Supplies explicit subscription gateway responses without Stripe or account persistence.
 *
 * @api
 */
final class FakeBillingGateway implements BillingGateway
{
    use FakeCalls;

    /**
     * Install a fresh gateway instance in the supplied container.
     */
    public static function fake(Container $container): self
    {
        $fake = new self;
        $container->instance(BillingGateway::class, $fake);

        return $fake;
    }

    /** Start or recover one scripted idempotent Checkout attempt. */
    public function checkout(
        BillingAccount $account,
        string $price,
        int $trialDays,
        string $successUrl,
        string $cancelUrl,
        CarbonImmutable $expiresAt,
        string $attemptId,
    ): CheckoutSession {
        $result = $this->invoke('checkout', [
            'account' => $account,
            'price' => $price,
            'trialDays' => $trialDays,
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'expiresAt' => $expiresAt,
            'attemptId' => $attemptId,
        ]);

        if (! $result instanceof CheckoutSession) {
            throw new TypeError('FakeBillingGateway::checkout requires a scripted CheckoutSession.');
        }

        return $result;
    }

    /** Return a scripted Customer Portal URL for the supplied customer handle. */
    public function portal(BillingAccount $account, string $returnUrl): string
    {
        $result = $this->invoke('portal', ['account' => $account, 'returnUrl' => $returnUrl]);

        if (! is_string($result)) {
            throw new TypeError('FakeBillingGateway::portal requires a scripted string.');
        }

        return $result;
    }

    /** Consume a contact synchronization script without changing the customer handle. */
    public function updateCustomer(BillingAccount $account, string $name, string $email): void
    {
        $this->invoke('updateCustomer', ['account' => $account, 'name' => $name, 'email' => $email]);
    }

    /**
     * Declare the exact native BillingGateway method allowlist.
     *
     * @return list<string>
     *
     * @internal
     */
    protected function fakeMethods(): array
    {
        return ['checkout', 'portal', 'updateCustomer'];
    }
}
