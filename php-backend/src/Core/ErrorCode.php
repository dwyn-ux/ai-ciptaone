<?php
declare(strict_types=1);

namespace Ciptaone\Core;

/**
 * Standard error codes conforming to PRD Section 7.
 */
class ErrorCode
{
    public const INVALID_REQUEST            = 'invalid_request';
    public const UNAUTHORIZED               = 'unauthorized';
    public const FORBIDDEN                  = 'forbidden';
    public const RATE_LIMIT_EXCEEDED        = 'rate_limit_exceeded';
    public const INSUFFICIENT_CREDITS       = 'insufficient_credits';
    public const MODEL_UNAVAILABLE          = 'model_unavailable';
    public const PROVIDER_TIMEOUT           = 'provider_timeout';
    public const PROVIDER_ERROR             = 'provider_error';
    public const PAYMENT_PENDING            = 'payment_pending';
    public const PAYMENT_VERIFICATION_FAILED= 'payment_verification_failed';
    public const INTERNAL_ERROR             = 'internal_error';
    public const NOT_FOUND                  = 'not_found';
    public const CSRF_INVALID               = 'csrf_invalid';
    public const SERVICE_UNAVAILABLE        = 'service_unavailable';
    public const BUDGET_EXCEEDED            = 'budget_exceeded';
    public const UPSTREAM_ERROR             = 'upstream_error';
}
