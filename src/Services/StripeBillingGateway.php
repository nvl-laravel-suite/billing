<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Carbon\CarbonImmutable;
use DomainException;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\ValueObjects\CheckoutSession;

/** Calls Stripe for hosted sessions while Cashier owns local subscription sync. */
final class StripeBillingGateway implements BillingGateway
{
    /** Create one hosted Checkout with idempotent customer and session requests. */
    public function checkout(
        BillingAccount $account,
        string $price,
        int $trialDays,
        string $successUrl,
        string $cancelUrl,
        CarbonImmutable $expiresAt,
        string $attemptId,
    ): CheckoutSession {
        if ($account->stripe_id === null) {
            $account->createAsStripeCustomer([], ['idempotency_key' => "{$attemptId}-customer"]);
        }

        $customerId = $account->stripe_id;
        if ($customerId === null) {
            throw new DomainException('Stripe did not return a billing customer.');
        }

        $subscriptionType = config('nvl-billing.subscription_type', 'default');
        if (! is_string($subscriptionType) || $subscriptionType === '') {
            throw new InvalidArgumentException('billing.subscription_type must be a nonempty string.');
        }

        $subscriptionData = [
            'metadata' => [
                'type' => $subscriptionType,
                'tenant_id' => $account->tenant_id,
            ],
        ];

        if ($trialDays > 0) {
            $subscriptionData['trial_end'] = (int) $expiresAt->addDays($trialDays)->timestamp;
            if (config('nvl-billing.trial.require_payment_method') === false) {
                $subscriptionData['trial_settings'] = ['end_behavior' => ['missing_payment_method' => 'cancel']];
            }
        }

        $session = Cashier::stripe()->checkout->sessions->create([
            'customer' => $customerId,
            'client_reference_id' => $account->tenant_id,
            'mode' => 'subscription',
            'line_items' => [['price' => $price, 'quantity' => 1]],
            'subscription_data' => $subscriptionData,
            'payment_method_collection' => config('nvl-billing.trial.require_payment_method') === false && $trialDays > 0
                ? 'if_required'
                : 'always',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => (int) $expiresAt->timestamp,
        ], ['idempotency_key' => "{$attemptId}-checkout"]);

        if ($session->id === '' || ! is_string($session->url) || $session->url === '') {
            throw new DomainException('Stripe did not return a hosted Checkout session.');
        }

        return new CheckoutSession($session->id, $session->url, $expiresAt);
    }

    /** Create one hosted portal session for the tenant's Stripe customer. */
    public function portal(BillingAccount $account, string $returnUrl): string
    {
        if ($account->stripe_id === null) {
            throw new InvalidArgumentException('A Stripe customer is required for the billing portal.');
        }

        $session = Cashier::stripe()->billingPortal->sessions->create([
            'customer' => $account->stripe_id,
            'return_url' => $returnUrl,
        ]);

        if ($session->url === '') {
            throw new DomainException('Stripe did not return a billing portal URL.');
        }

        return $session->url;
    }

    /** Synchronize an authorized billing contact to an existing Stripe customer. */
    public function updateCustomer(BillingAccount $account, string $name, string $email): void
    {
        if ($account->stripe_id === null) {
            throw new InvalidArgumentException('A Stripe customer is required to update its billing contact.');
        }

        Cashier::stripe()->customers->update($account->stripe_id, [
            'name' => $name,
            'email' => $email,
        ]);
    }
}
