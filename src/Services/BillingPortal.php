<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Enums\BillingResponseCode;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Creates a portal entry for an authorized tenant billing administrator.
 *
 * @api
 */
final readonly class BillingPortal
{
    /** Inject permission, tenant, and Stripe boundaries. */
    public function __construct(
        private BillingOwnerBoundary $ownership,
        private BillingManagementAccess $management,
        private BillingGateway $gateway,
        private BillingReturnUrls $returnUrls,
    ) {}

    /** Return a hosted portal URL without exposing another owner's customer. */
    public function url(OwnerReference $owner, Authenticatable $actor, string $returnUrl): string
    {
        if (config('nvl-billing.enabled') !== true) {
            throw BillingException::because(BillingResponseCode::FeatureDisabled, 'Billing is disabled.');
        }

        $actor = $this->ownership->resolveActor($owner, $actor);
        $this->management->assertCanManage($actor, $owner);
        $this->returnUrls->validate($returnUrl);
        $account = BillingModelRegistry::accountQuery()->where('owner_scope_key', $owner->scopeKey())->first();

        if ($account === null || $account->stripe_id === null) {
            throw BillingException::because(BillingResponseCode::SubscriptionConflict, 'The tenant has no Stripe billing customer.');
        }

        return $this->gateway->portal($account, $returnUrl);
    }
}
