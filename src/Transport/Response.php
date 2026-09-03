<?php

declare(strict_types=1);

namespace HelloJade\Intake\Transport;

/**
 * A raw HTTP response. Header names are lower-cased; the first value of a
 * repeated header wins (the edge and the application both set X-Request-Id,
 * to the same value).
 */
final class Response
{
    /**
     * @param array<string, string> $headers lower-cased names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
