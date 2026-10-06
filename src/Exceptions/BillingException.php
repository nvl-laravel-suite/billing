<?php

declare(strict_types=1);

namespace Nvl\Billing\Exceptions;

use DomainException;
use Nvl\Billing\Enums\BillingResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use Throwable;

/** Expected Billing domain, configuration and infrastructure failures.
 * @api
 */
class BillingException extends DomainException implements RespondableException
{
    use InteractsWithPackageFailure;

    private ?ExceptionResponse $failureResponse = null;

    /**
     * Preserve diagnostic copy and a previous cause independently of public copy.
     *
     * @param  array<string, mixed>  $publicContext
     */
    public static function because(BillingResponseCode $code, string $diagnosticMessage, array $publicContext = [], ?Throwable $previous = null): self
    {
        $exception = new self($diagnosticMessage, previous: $previous);
        $status = match ($code) {
            BillingResponseCode::FeatureDisabled => 404,
            BillingResponseCode::TenantInactive, BillingResponseCode::CheckoutConflict, BillingResponseCode::SubscriptionConflict,
            BillingResponseCode::ProviderIdentityMismatch, BillingResponseCode::OperationConflict, BillingResponseCode::PaymentStateInvalid,
            BillingResponseCode::ReconciliationRequired => 409,
            BillingResponseCode::InvalidConfiguration, BillingResponseCode::StorageUnavailable => 500,
            default => 422,
        };
        $exception->failureResponse = new ExceptionResponse('billing', $code, $status, $publicContext);

        return $exception;
    }

    /** Resolve explicitly safe metadata for this failure. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return $this->failureResponse ?? new ExceptionResponse('billing', BillingResponseCode::OperationFailed);
    }
}
