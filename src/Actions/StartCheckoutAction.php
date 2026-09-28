<?php

declare(strict_types=1);

namespace Nvl\Billing\Actions;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Billing\Catalog\PlanCatalog;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\ValueObjects\CheckoutAttempt;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Starts one tenant's hosted subscription Checkout without duplicate sessions. */
final readonly class StartCheckoutAction
{
    /** Inject the tenant, permission, catalog, and Stripe boundaries. */
    public function __construct(
        private TenantDirectory $tenants,
        private BillingManagementAccess $management,
        private PlanCatalog $catalog,
        private BillingGateway $gateway,
    ) {}

    /**
     * Start or return the existing Checkout for one tenant and Price.
     *
     * @throws DomainException When the tenant has a subscription or a different pending Price
     * @throws InvalidArgumentException When checkout input is invalid
     */
    public function execute(
        TenantId $tenant,
        Authenticatable $actor,
        string $billingEmail,
        string $plan,
        string $interval,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSession {
        if (config('billing.enabled') !== true) {
            throw new DomainException('Billing is disabled.');
        }

        $this->management->assertCanManage($actor, $tenant);
        if ($this->tenants->find($tenant)->status !== TenantStatus::Active) {
            throw new DomainException('Only active tenants may start billing.');
        }

        if (filter_var($billingEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid billing email is required.');
        }

        $price = $this->catalog->priceFor($plan, $interval);
        $account = $this->accountFor($tenant, $billingEmail);
        $attempt = DB::connection($account->getConnectionName())->transaction(
            fn (): CheckoutAttempt => $this->reserveAttempt($account, $price),
        );

        if ($attempt->session !== null) {
            return $attempt->session;
        }

        $session = $this->gateway->checkout(
            $attempt->account,
            $price,
            $attempt->trialDays,
            $successUrl,
            $cancelUrl,
            $attempt->expiresAt,
            $attempt->id,
        );

        BillingAccount::query()->whereKey($account->id)
            ->where('pending_checkout_attempt_id', $attempt->id)
            ->update([
                'pending_checkout_session_id' => $session->id,
                'pending_checkout_url' => $session->url,
            ]);

        return $session;
    }

    /** Create the unique tenant account, recovering a competing insert. */
    private function accountFor(TenantId $tenant, string $email): BillingAccount
    {
        try {
            return BillingAccount::query()->firstOrCreate(
                ['tenant_id' => $tenant->value],
                ['email' => $email],
            );
        } catch (UniqueConstraintViolationException) {
            return BillingAccount::query()->where('tenant_id', $tenant->value)->firstOrFail();
        }
    }

    /** Reserve or recover an attempt while the tenant account row is locked. */
    private function reserveAttempt(BillingAccount $account, string $price): CheckoutAttempt
    {
        $account = BillingAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        $type = config('billing.subscription_type', 'default');
        if (! is_string($type) || $type === '') {
            throw new InvalidArgumentException('billing.subscription_type must be a nonempty string.');
        }

        $hasExistingSubscription = BillingSubscription::query()
            ->where('billing_account_id', $account->id)
            ->where('type', $type)
            ->where(function ($query): void {
                $query->whereIn('stripe_status', ['active', 'trialing', 'past_due', 'paused', 'unpaid'])
                    ->orWhere('ends_at', '>', now());
            })
            ->exists();
        if ($hasExistingSubscription) {
            throw new DomainException('The tenant already has a subscription; use the billing portal.');
        }

        $expiry = $account->pending_checkout_expires_at;
        if ($account->pending_checkout_attempt_id !== null && $expiry?->isFuture() === true) {
            if ($account->pending_checkout_price !== $price) {
                throw new DomainException('A Checkout for another plan is already pending.');
            }

            $session = $account->pending_checkout_session_id !== null && $account->pending_checkout_url !== null
                ? new CheckoutSession($account->pending_checkout_session_id, $account->pending_checkout_url, $expiry)
                : null;

            return new CheckoutAttempt($account, $account->pending_checkout_attempt_id, $price, $account->pending_checkout_trial_days ?? 0, $expiry, $session);
        }

        $expiresAt = CarbonImmutable::now()->addHours(2);
        $attemptId = (string) Str::uuid();
        $account->forceFill([
            'pending_checkout_attempt_id' => $attemptId,
            'pending_checkout_price' => $price,
            'pending_checkout_expires_at' => $expiresAt,
            'pending_checkout_trial_days' => $this->trialDays($account),
            'pending_checkout_session_id' => null,
            'pending_checkout_url' => null,
        ])->save();

        return new CheckoutAttempt($account, $attemptId, $price, $account->pending_checkout_trial_days ?? 0, $expiresAt, null);
    }

    /** Grant the configured free trial only to a tenant that has not used one. */
    private function trialDays(BillingAccount $account): int
    {
        $days = config('billing.trial.days', 0);
        if (! is_int($days) || ($days !== 0 && $days < 2)) {
            throw new InvalidArgumentException('billing.trial.days must be zero or at least two.');
        }

        return $account->trial_consumed_at === null ? $days : 0;
    }
}
