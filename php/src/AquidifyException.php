<?php

declare(strict_types=1);

namespace Aquidify;

/**
 * A failed API call. $errorCode is the API's stable code (see openapi.yaml):
 * unauthorized, rate_limited, invalid_request, model_unavailable, ... or
 * "network" when the request never got a response.
 */
final class AquidifyException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    /** True when the same request may succeed later (rate limit, model busy, timeout, network). */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, ['rate_limited', 'model_unavailable', 'timeout', 'network'], true);
    }
}
