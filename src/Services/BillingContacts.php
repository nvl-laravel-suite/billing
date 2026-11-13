<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Enums\BillingResponseCode;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Updates the owner-owned contact used for Stripe billing correspondence.
 *
 * @api
 */
final readonly class BillingContacts
{
    /** Inject tenant, authorization, and Stripe boundaries. */
    public function __construct(
        private BillingOwnerBoundary $ownership,
        private BillingManagementAccess $management,
        private BillingGateway $gateway,
        private ?BillingEvents $events = null,
    ) {}

    /** Change the contact only after the host authorizes this owner. */
    public function update(OwnerReference $owner, Authenticatable $actor, string $name, string $email): BillingAccount
    {
        if (config('nvl-billing.enabled') !== true) {
            throw BillingException::because(BillingResponseCode::FeatureDisabled, 'Billing is disabled.');
        }

        $actor = $this->ownership->resolveActor($owner, $actor);
        $this->management->assertCanManage($actor, $owner);

        $name = trim($name);
        $email = trim($email);
        if ($name === '' || strlen($name) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new InvalidArgumentException('A valid billing contact name and email are required.');
        }

        $connection = BillingModelRegistry::newAccount()->getConnection();
        $account = BillingModelRegistry::accountQuery()->where('owner_scope_key', $owner->scopeKey())->first();
        if ($account === null) {
            try {
                return $connection->transaction(function () use ($name, $email): BillingAccount {
                    $created = BillingModelRegistry::accountQuery()->create(['name' => $name, 'email' => $email]);
                    ($this->events ?? BillingEvents::current())->accountChanged($created, 'created');

                    return $created;
                });
            } catch (UniqueConstraintViolationException) {
                $account = BillingModelRegistry::accountQuery()->where('owner_scope_key', $owner->scopeKey())->firstOrFail();
            }
        }

        $syncedStripeId = null;
        while (true) {
            if ($account->stripe_id !== null && $account->stripe_id !== $syncedStripeId) {
                if ($account->getConnection()->transactionLevel() !== 0) {
                    throw new DomainException('Remote billing contact updates must start outside a database transaction.');
                }
                $this->gateway->updateCustomer($account, $name, $email);
                $syncedStripeId = $account->stripe_id;
            }

            $persisted = $account->getConnection()->transaction(function () use ($account, $name, $email, $syncedStripeId): bool {
                $locked = $account->newQuery()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
                $account->setRawAttributes($locked->getAttributes(), true);
                if ($account->stripe_id !== null && $account->stripe_id !== $syncedStripeId) {
                    return false;
                }
                $account->forceFill(['name' => $name, 'email' => $email]);
                $changed = $account->isDirty(['name', 'email']);
                $account->save();
                if ($changed) {
                    ($this->events ?? BillingEvents::current())->accountChanged($account, 'contact_updated');
                }

                return true;
            });

            if ($persisted) {
                return $account;
            }
        }
    }
}
