<?php

declare(strict_types=1);

use Nvl\Billing\Tests\BillingTestCase;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\OwnershipTestCase;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

if (class_exists(OwnershipTestCase::class)) {
    require_once __DIR__.'/BillingTestCase.php';
    uses(BillingTestCase::class)->in(__DIR__.'/Feature');
}

/** Run each existing feature scenario inside a real admitted owner. */
function billingNativeRun(Closure $callback): void
{
    app(OwnershipRunner::class)->run(
        Company::query()->findOrFail(7),
        Actor::query()->findOrFail(1),
        $callback,
    );
}

function billingNativeOwner(int $key = 7): OwnerReference
{
    return app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail($key));
}

function billingNativeCapturedRun(OwnerReference $owner, Closure $callback): mixed
{
    return app(OwnershipRunner::class)->run(Company::query()->findOrFail((int) $owner->key), Actor::query()->findOrFail(1), $callback);
}
