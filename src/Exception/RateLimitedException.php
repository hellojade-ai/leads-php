<?php

declare(strict_types=1);

namespace HelloJade\Intake\Exception;

/**
 * 429 — thrown only after the retry policy's rate-limit budget is spent.
 * `$retryAfter` is the server's floor in seconds.
 */
class RateLimitedException extends ApiException
{
    public function __construct(
        public readonly int $retryAfter,
        int $status,
        string $apiCode,
        ?string $message = null,
        ?string $requestId = null,
        ?string $body = null,
        int $attempts = 1,
    ) {
        parent::__construct($status, $apiCode, $message, $requestId, $body, $attempts);
    }
}
