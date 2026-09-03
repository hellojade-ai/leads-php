<?php

declare(strict_types=1);

namespace HelloJade\Intake\Exception;

/**
 * The request never produced an HTTP response: DNS, connect, TLS, timeout,
 * reset. The client retries these (rule 5) and throws only once the retry
 * policy is exhausted. The API key is never part of the message.
 */
class TransportException extends IntakeException
{
    public function __construct(
        string $message,
        public readonly ?string $requestId = null,
        public readonly int $attempts = 1,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
