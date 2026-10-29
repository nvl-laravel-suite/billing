<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use InvalidArgumentException;

/**
 * Enforces the host's redirect allowlist for native Checkout and Portal operations.
 *
 * @internal
 */
final readonly class BillingReturnUrls
{
    /** Reject unapproved redirects before any local reservation or provider call. */
    public function validate(string ...$urls): void
    {
        $hosts = config()->array('nvl-billing.checkout.return_hosts');
        foreach ($urls as $url) {
            $parts = parse_url($url);
            if ($parts === false || filter_var($url, FILTER_VALIDATE_URL) === false
                || ($parts['scheme'] ?? null) !== 'https'
                || ! in_array(strtolower($parts['host'] ?? ''), $hosts, true)
                || isset($parts['user']) || isset($parts['pass'])
                || (isset($parts['port']) && $parts['port'] !== 443)) {
                throw new InvalidArgumentException('Billing return URLs must use an allowed HTTPS host.');
            }
        }
    }
}
