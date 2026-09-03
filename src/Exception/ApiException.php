<?php

declare(strict_types=1);

namespace HelloJade\Intake\Exception;

/**
 * The API answered with a non-success status. `$code` is the API's own error
 * string ("unauthorized", "invalid_json", "body_too_large", "not_accepting",
 * ...). `$requestId` is the handle support needs when there is no event_id.
 * The API key is never part of the message.
 */
class ApiException extends IntakeException
{
    /** @var string The API's error code, e.g. "validation_failed". */
    public readonly string $apiCode;

    public function __construct(
        public readonly int $status,
        string $apiCode,
        ?string $message = null,
        public readonly ?string $requestId = null,
        public readonly ?string $body = null,
        public readonly int $attempts = 1,
    ) {
        $this->apiCode = $apiCode;
        $text = "intake API {$status} {$apiCode}";
        if ($message !== null && $message !== '') {
            $text .= ": {$message}";
        }
        if ($requestId !== null) {
            $text .= " (request_id={$requestId})";
        }
        parent::__construct($text, $status);
    }

    /**
     * Only 5xx is worth retrying; every other status is a fact about the
     * request that a retry will not change.
     */
    public function isRetryable(): bool
    {
        return $this->status >= 500;
    }
}
