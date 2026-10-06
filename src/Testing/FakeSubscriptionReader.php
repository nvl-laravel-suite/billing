<?php

declare(strict_types=1);

namespace Nvl\Billing\Testing;

use Illuminate\Contracts\Container\Container;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Support\Testing\FakeCalls;
use TypeError;

/**
 * Supplies explicit customer subscription lists without remote reads or reconciliation.
 *
 * @api
 */
final class FakeSubscriptionReader implements SubscriptionReader
{
    use FakeCalls;

    /**
     * Install a fresh reader instance in the supplied container.
     */
    public static function fake(Container $container): self
    {
        $fake = new self;
        $container->instance(SubscriptionReader::class, $fake);

        return $fake;
    }

    /**
     * Return all scripted current and historical subscription objects for one customer.
     *
     * @return list<array<string, mixed>>
     */
    public function forCustomer(string $customerId): array
    {
        $result = $this->invoke('forCustomer', ['customerId' => $customerId]);

        if (! is_array($result) || ! array_is_list($result)) {
            throw new TypeError('FakeSubscriptionReader::forCustomer requires a scripted subscription list.');
        }

        $subscriptions = [];
        foreach ($result as $row) {
            if (! is_array($row)) {
                throw new TypeError('FakeSubscriptionReader::forCustomer requires subscription arrays.');
            }

            $subscription = [];
            foreach ($row as $key => $value) {
                if (! is_string($key)) {
                    throw new TypeError('FakeSubscriptionReader::forCustomer requires string subscription field names.');
                }

                $subscription[$key] = $value;
            }

            $subscriptions[] = $subscription;
        }

        return $subscriptions;
    }

    /**
     * Declare the exact native SubscriptionReader method allowlist.
     *
     * @return list<string>
     *
     * @internal
     */
    protected function fakeMethods(): array
    {
        return ['forCustomer'];
    }
}
