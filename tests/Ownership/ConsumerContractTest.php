<?php

declare(strict_types=1);

use Nvl\Billing\Actions\StartCheckoutAction;
use Nvl\Billing\Actions\UpdateBillingContactAction;
use Nvl\Billing\Contracts\BillingAccessContract;
use Nvl\Billing\Contracts\BillingPortalContract;
use Nvl\Billing\Contracts\StartCheckoutContract;
use Nvl\Billing\Contracts\UpdateBillingContactContract;
use Nvl\Billing\Services\BillingAccess;
use Nvl\Billing\Services\BillingPortal;
use Nvl\Billing\Tests\OwnerScopeTestCase;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

uses(OwnerScopeTestCase::class);

it('exposes native owner contracts and respects consumer replacements without legacy argument unions', function (string $contract, string $implementation, string $method): void {
    $interface = new ReflectionClass($contract);
    $concrete = new ReflectionClass($implementation);
    expect($concrete->implementsInterface($contract))->toBeTrue()
        ->and($concrete->isFinal())->toBeTrue()->and($concrete->isReadOnly())->toBeTrue()
        ->and(app($contract))->toBeInstanceOf($implementation)
        ->and(array_map(fn (ReflectionMethod $member): string => $member->getName(), $interface->getMethods()))->toBe([$method]);
    foreach ([$interface, $concrete] as $reflection) {
        $member = $reflection->getMethod($method);
        $parameter = $member->getParameters()[0];
        expect($parameter->getName())->toBe('owner')->and((string) $parameter->getType())->toBe(OwnerReference::class)
            ->and($parameter->isDefaultValueAvailable())->toBeFalse()
            ->and($member->getDocComment())->toBeString();
    }
    $replacement = Mockery::mock($contract);
    app()->instance($contract, $replacement);
    expect(app($contract))->toBe($replacement);
})->with([
    [StartCheckoutContract::class, StartCheckoutAction::class, 'execute'],
    [UpdateBillingContactContract::class, UpdateBillingContactAction::class, 'execute'],
    [BillingAccessContract::class, BillingAccess::class, 'forOwner'],
    [BillingPortalContract::class, BillingPortal::class, 'url'],
]);
