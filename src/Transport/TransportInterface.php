<?php

declare(strict_types=1);

namespace HelloJade\Intake\Transport;

use HelloJade\Intake\Exception\TransportException;

/**
 * The one HTTP call the client needs. Implement this to route requests through
 * any HTTP stack; the default is CurlTransport.
 */
interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException when no HTTP response was produced (DNS, connect, TLS, timeout, reset)
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
