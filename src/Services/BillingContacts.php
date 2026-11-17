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

        $synchronized = null;
        while (true) {
            if ($account->stripe_id !== null && $this->contact($account) !== $synchronized) {
                if ($account->getConnection()->transactionLevel() !== 0) {
                    throw new DomainException('Remote billing contact updates must start outside a database transaction.');
                }
                $synchronized = $this->contact($account);
                $this->gateway->updateCustomer($account, $name, $email);
            }

            $persisted = $account->getConnection()->transaction(function () use ($account, $name, $email, $synchronized): bool {
                $locked = $account->newQuery()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
                $account->setRawAttributes($locked->getAttributes(), true);
                if ($account->stripe_id !== null && $this->contact($account) !== $synchronized) {
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

    /**
     * Capture the provider identity and contact a remote update was based on.
     *
     * @return array{stripe_id:mixed,name:mixed,email:mixed}
     */
    private function contact(BillingAccount $account): array
    {
        return ['stripe_id' => $account->stripe_id, 'name' => $account->name, 'email' => $account->email];
    }
}
