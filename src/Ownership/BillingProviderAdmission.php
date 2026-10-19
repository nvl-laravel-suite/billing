<?php

declare(strict_types=1);

namespace Nvl\Billing\Ownership;

use Closure;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingWebhookEvent;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Contracts\OwnershipSystemRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Symfony\Component\HttpFoundation\Response;

/** Resolves stored customer identity only after the webhook route verifies its signature. @internal */
final readonly class BillingProviderAdmission
{
    /** Retain narrow identity, maintenance admission and package receipt boundaries. */
    public function __construct(private OwnershipResourceRegistry $resources, private BillingIntegrityGuard $integrity,
        private OwnershipSystemRunner $runner, private OwnershipBoundary $boundary, private OwnershipReadiness $readiness,
        private OwnershipContextResolver $context) {}

    /**
     * Apply verified provider facts in the stored customer's owner partition.
     *
     * @param  array<string,mixed>  $payload
     * @param  Closure(): Response  $apply
     */
    public function run(array $payload, Closure $apply): Response
    {
        if (! $this->native()) {
            return $apply();
        }
        $this->integrity->assertReady();
        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;
        if (! is_array($object) || ! is_string($type) || ! in_array($type, [
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted', 'customer.updated', 'customer.deleted',
        ], true)) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }
        $customer = str_starts_with($type, 'customer.subscription.') ? ($object['customer'] ?? null) : ($object['id'] ?? null);
        if (! is_string($customer) || $customer === '') {
            throw new OwnershipViolation('Verified billing events require their exact customer identity.');
        }
        $model = new BillingAccount;
        $row = $model->getConnection()->table($model->getTable())->useWritePdo()->where('stripe_id', $customer)
            ->first(['owner_scope_key', 'owner_reference']);
        if ($row === null) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }
        if (! is_string($row->owner_reference)) {
            throw new OwnershipViolation;
        }
        $owner = OwnerReference::fromJson($row->owner_reference);
        if ($owner->scopeKey() !== $row->owner_scope_key) {
            throw new OwnershipViolation;
        }

        return $this->runner->runSystem($owner, 'billing.provider-callback', $apply);
    }

    /**
     * Return server-owned receipt attributes or reject a provider receipt that crossed partitions.
     *
     * @return array<string,int|string|null>|null
     */
    public function receipt(string $eventId, string $type): ?array
    {
        if (! $this->native()) {
            return [];
        }
        $this->readiness->assertActive('billing.webhooks');
        $model = new BillingWebhookEvent;
        $row = $model->getConnection()->table($model->getTable())->useWritePdo()->where('stripe_event_id', $eventId)
            ->first(['type', 'owner_scope_key']);
        if ($row !== null) {
            $owner = $this->context->current()->requireOwner();
            if ($row->type !== $type || ! in_array($row->owner_scope_key, [$owner->scopeKey(), 'central'], true)) {
                throw new OwnershipViolation('A provider event cannot change its durable identity or original owner.');
            }

            return null;
        }

        return $this->boundary->attributes('billing.webhooks');
    }

    /** Identify native declarations without granting fallback access to adopted storage. */
    private function native(): bool
    {
        return $this->resources->get('billing.accounts')->ownership->strategy !== 'unscoped';
    }
}
