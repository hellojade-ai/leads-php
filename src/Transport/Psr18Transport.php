<?php

declare(strict_types=1);

namespace HelloJade\Intake\Transport;

use HelloJade\Intake\Exception\TransportException;

/**
 * Optional adapter for any PSR-18 client. psr/http-client and
 * psr/http-factory are NOT required by this package; the constructor is
 * duck-typed so the interfaces only need to exist when you use this class.
 *
 *   $transport = new Psr18Transport($psr18Client, $requestFactory, $streamFactory);
 *   $client = new Client(apiKey: $key, transport: $transport);
 *
 * Note: the per-request timeout is the PSR-18 client's responsibility;
 * configure it there.
 */
final class Psr18Transport implements TransportInterface
{
    /**
     * @param object $client         a Psr\Http\Client\ClientInterface
     * @param object $requestFactory a Psr\Http\Message\RequestFactoryInterface
     * @param object $streamFactory  a Psr\Http\Message\StreamFactoryInterface
     */
    public function __construct(
        private readonly object $client,
        private readonly object $requestFactory,
        private readonly object $streamFactory,
    ) {
        foreach ([[$client, 'sendRequest'], [$requestFactory, 'createRequest'], [$streamFactory, 'createStream']] as [$obj, $m]) {
            if (!method_exists($obj, $m)) {
                throw new \InvalidArgumentException(get_class($obj) . " does not implement {$m}()");
            }
        }
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $request = $this->requestFactory->createRequest(strtoupper($method), $url);
        foreach ($headers as $k => $v) {
            $request = $request->withHeader($k, $v);
        }
        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (\Throwable $e) {
            throw new TransportException(get_class($e) . ': ' . $e->getMessage(), previous: $e);
        }

        $out = [];
        foreach ($response->getHeaders() as $name => $values) {
            $out[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return new Response((int) $response->getStatusCode(), $out, (string) $response->getBody());
    }
}
