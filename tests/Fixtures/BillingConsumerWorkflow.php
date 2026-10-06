<?php

declare(strict_types=1);

namespace Nvl\Billing\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Nvl\Billing\Contracts\BillingAccessContract;
use Nvl\Billing\Contracts\BillingPortalContract;
use Nvl\Billing\Contracts\StartCheckoutContract;
use Nvl\Billing\Contracts\UpdateBillingContactContract;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\ValueObjects\BillingSnapshot;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Composes Billing's supported boundaries in a host-owned application service. */
final readonly class BillingConsumerWorkflow
{
    /** Receive only the four supported Billing workflow interfaces. */
    public function __construct(
        private StartCheckoutContract $start,
        private UpdateBillingContactContract $contact,
        private BillingAccessContract $access,
        private BillingPortalContract $portal,
    ) {}

    /**
     * Prepare the host's tenant billing screen from typed workflow results.
     *
     * @return array{checkout: CheckoutSession, contact: BillingAccount, access: BillingSnapshot, reports: bool, projects: int, portal: string}
     */
    public function prepare(TenantId $tenant, Authenticatable $actor): array
    {
        $contact = $this->contact->execute($tenant, $actor, 'Host Billing', 'billing@example.test');
        $access = $this->access->forTenant($tenant);

        return [
            'checkout' => $this->start->execute($tenant, $actor, $contact->email, 'pro', 'monthly', 'https://app.example.test/success', 'https://app.example.test/cancel'),
            'contact' => $contact,
            'access' => $access,
            'reports' => $access->allows('reports'),
            'projects' => $access->limit('projects'),
            'portal' => $this->portal->url($tenant, $actor, 'https://app.example.test/billing'),
        ];
    }

    /** Expose injected identity for host discovery and replacement regressions. */
    public function dependency(string $contract): object
    {
        return match ($contract) {
            StartCheckoutContract::class => $this->start,
            UpdateBillingContactContract::class => $this->contact,
            BillingAccessContract::class => $this->access,
            BillingPortalContract::class => $this->portal,
            default => throw new InvalidArgumentException('Unknown Billing workflow contract.'),
        };
    }
}
