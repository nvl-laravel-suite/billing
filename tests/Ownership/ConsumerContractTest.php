<?php

declare(strict_types=1);

use Nvl\Billing\Services\BillingAccess;
use Nvl\Billing\Services\BillingPortal;
use Nvl\Billing\Tests\OwnerScopeTestCase;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

uses(OwnerScopeTestCase::class);

it('exposes focused native services with explicit owner identities', function (string $implementation, string $method): void {
    $concrete = new ReflectionClass($implementation);
    expect($concrete->isFinal())->toBeTrue()->and($concrete->isReadOnly())->toBeTrue()
        ->and(app($implementation))->toBeInstanceOf($implementation)
        ->and($concrete->getInterfaceNames())->toBe([]);
    $member = $concrete->getMethod($method);
    $parameter = $member->getParameters()[0];
    expect($parameter->getName())->toBe('owner')->and((string) $parameter->getType())->toBe(OwnerReference::class)
        ->and($parameter->isDefaultValueAvailable())->toBeFalse()
        ->and($member->getDocComment())->toBeString();
})->with([
    [BillingAccess::class, 'forOwner'],
    [BillingPortal::class, 'url'],
]);
