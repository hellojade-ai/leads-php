<?php

declare(strict_types=1);

namespace HelloJade\Intake;

use HelloJade\Intake\Exception\ApiException;
use HelloJade\Intake\Exception\RateLimitedException;
use HelloJade\Intake\Exception\TransportException;
use HelloJade\Intake\Exception\UnauthorizedException;
use HelloJade\Intake\Exception\ValidationException;
use HelloJade\Intake\Transport\CurlTransport;
use HelloJade\Intake\Transport\Response;
use HelloJade\Intake\Transport\TransportInterface;

/**
 * The HTTP client. cURL by default, no runtime dependencies.
 *
 *   $client = new Client(apiKey: getenv('HELLOJADE_API_KEY'));
 *   $client->checkKey();                                   // true (422 from the API = key is valid)
 *   $client->submitLead($lead, 'acme-leads:1234');
 *
 * Every call retries transport errors and 5xx with exponential backoff and
 * waits out 429s according to Retry-After (see RetryPolicy). A 4xx other than
 * 429 is never retried.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://intake.hellojade.ai';
    public const DEFAULT_TIMEOUT = 20.0;
    public const DEFAULT_USER_AGENT = 'hellojade-intake-php/' . Version::STRING;

    public const INTAKE_PATH = '/v1/intake';
    public const VOCABULARY_PATH = '/v1/vocabulary';
    public const HEALTH_PATH = '/healthz';

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly float $timeout;
    private readonly string $userAgent;
    private readonly RetryPolicy $retryPolicy;
    private readonly TransportInterface $transport;
    /** @var callable(float): void */
    private $sleeper;

    /**
     * @param string                       $apiKey      the key hellojade issued. Never logged, never in an exception message.
     * @param string                       $baseUrl     https://intake.hellojade.ai; configuration, not a constant.
     * @param float                        $timeout     seconds, applied to connect and total. The API bounds its own handler at 20 s.
     * @param string|null                  $userAgent   appended to the package's own UA when given.
     * @param RetryPolicy|null             $retryPolicy
     * @param TransportInterface|null      $transport   defaults to CurlTransport
     * @param (callable(float): void)|null $sleeper     receives seconds to wait; injectable for tests.
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = self::DEFAULT_TIMEOUT,
        ?string $userAgent = null,
        ?RetryPolicy $retryPolicy = null,
        ?TransportInterface $transport = null,
        ?callable $sleeper = null,
    ) {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
        $this->apiKey = $apiKey;
        $baseUrl = rtrim($baseUrl, '/');
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || parse_url($baseUrl, PHP_URL_HOST) === null) {
            throw new \InvalidArgumentException("baseUrl must be an http(s) URL, got \"{$baseUrl}\"");
        }
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
        $this->userAgent = $userAgent !== null && $userAgent !== ''
            ? self::DEFAULT_USER_AGENT . ' ' . $userAgent
            : self::DEFAULT_USER_AGENT;
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
        $this->transport = $transport ?? new CurlTransport();
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /** Keep the key out of var_dump / print_r output. */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => '[REDACTED]', 'timeout' => $this->timeout];
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getRetryPolicy(): RetryPolicy
    {
        return $this->retryPolicy;
    }

    /**
     * Section 1 of the integration brief: POST "{}" with the key. The API
     * authenticates BEFORE it validates, so a 422 proves the key is valid and
     * that nothing was stored. Returns true on 422, false on 401, and throws
     * ApiException for anything else.
     */
    public function checkKey(?string $requestId = null): bool
    {
        $res = $this->perform('POST', self::INTAKE_PATH, body: '{}', requestId: $requestId);

        return match ($res['status']) {
            422 => true,
            401 => false,
            default => throw $this->errorFor($res),
        };
    }

    /**
     * Submit one lead. Returns Accepted for both 202 ("accepted") and 200
     * ("duplicate" — the ORIGINAL event_id, which is success on a retry).
     *
     * @param Lead|array<string, mixed> $lead
     * @param string                    $idempotencyKey YOUR stable id for this lead, namespaced to you
     *                                                  (e.g. "acme-leads:1234"). Not a timestamp, not a fresh
     *                                                  UUID per attempt. Dedupe is per tenant, so a bare "1234"
     *                                                  can collide with another source's lead and silently
     *                                                  never be stored.
     * @param string|null               $requestId      your correlation id (<= 64 chars); one is generated when
     *                                                  omitted. It comes back in the X-Request-Id header and in
     *                                                  any error body.
     *
     * @throws ValidationException   422 — fix the body, do not retry unchanged.
     * @throws UnauthorizedException 401.
     * @throws RateLimitedException  429 after the rate-limit wait budget is spent.
     * @throws ApiException          any other non-success status.
     * @throws TransportException    no response after the retry policy is exhausted.
     */
    public function submitLead(Lead|array $lead, string $idempotencyKey, ?string $requestId = null): Accepted
    {
        if (trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('idempotencyKey is required (rule 2)');
        }
        if (strlen($idempotencyKey) > 200) {
            throw new \InvalidArgumentException('idempotencyKey must be <= 200 characters');
        }
        $payload = $lead instanceof Lead ? $lead->toArray() : $lead;
        $json = json_encode((object) $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $res = $this->perform('POST', self::INTAKE_PATH, body: $json, headers: ['Idempotency-Key' => $idempotencyKey], requestId: $requestId);

        return match ($res['status']) {
            200, 202 => Accepted::fromResponse($res['status'], $res['json'] ?? [], $res['requestId']),
            default => throw $this->errorFor($res),
        };
    }

    /**
     * GET /v1/vocabulary — unauthenticated, cacheable for five minutes. Fetch
     * it rather than hard-coding project_area terms.
     */
    public function vocabulary(?string $requestId = null): Vocabulary
    {
        $res = $this->perform('GET', self::VOCABULARY_PATH, requestId: $requestId, auth: false);
        if ($res['status'] !== 200) {
            throw $this->errorFor($res);
        }

        return Vocabulary::fromBody($res['json'] ?? []);
    }

    /** GET /healthz — liveness. Returns Health for both 200 and 503. */
    public function health(?string $requestId = null): Health
    {
        $res = $this->perform('GET', self::HEALTH_PATH, requestId: $requestId, auth: false, retry5xx: false);
        if (!in_array($res['status'], [200, 503], true)) {
            throw $this->errorFor($res);
        }

        return Health::fromResponse($res['status'], $res['json'] ?? []);
    }

    /**
     * One logical request with the retry policy applied.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, json: array<string, mixed>|null, requestId: ?string, attempts: int, retryAfter: int}
     */
    private function perform(
        string $method,
        string $path,
        ?string $body = null,
        array $headers = [],
        ?string $requestId = null,
        bool $auth = true,
        bool $retry5xx = true,
    ): array {
        $requestId = $requestId !== null && $requestId !== '' ? $requestId : bin2hex(random_bytes(8));
        $attempt = 0;
        $rateLimitWaits = 0;
        $policy = $this->retryPolicy;
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        while (true) {
            $attempt++;
            $h = [
                'User-Agent' => $this->userAgent,
                'Accept' => 'application/json',
                'X-Request-Id' => $requestId,
            ];
            if ($auth) {
                $h['X-API-Key'] = $this->apiKey;
            }
            foreach ($headers as $k => $v) {
                $h[$k] = $v;
            }
            if ($body !== null) {
                $h['Content-Type'] = 'application/json';
            }

            try {
                $res = $this->transport->send($method, $url, $h, $body, $this->timeout);
            } catch (TransportException $e) {
                if ($attempt >= $policy->maxAttempts) {
                    throw new TransportException(
                        "intake request failed after {$attempt} attempt(s): " . $e->getMessage(),
                        $requestId,
                        $attempt,
                        $e,
                    );
                }
                ($this->sleeper)($policy->backoff($attempt));
                continue;
            }

            $parsed = $this->parse($res, $requestId, $attempt);
            $status = $parsed['status'];

            if ($status === 429) {
                $rateLimitWaits++;
                if ($rateLimitWaits > $policy->maxRateLimitWaits) {
                    throw $this->errorFor($parsed);
                }
                ($this->sleeper)($policy->rateLimitDelay($parsed['retryAfter'], $rateLimitWaits));
                $attempt--; // a 429 does not consume a delivery attempt
                continue;
            }

            if ($status >= 500 && $retry5xx && $attempt < $policy->maxAttempts) {
                ($this->sleeper)($policy->backoff($attempt));
                continue;
            }

            return $parsed;
        }
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string, json: array<string, mixed>|null, requestId: ?string, attempts: int, retryAfter: int}
     */
    private function parse(Response $res, string $requestId, int $attempt): array
    {
        $json = null;
        $decoded = json_decode($res->body, true);
        if (is_array($decoded) && (array_is_list($decoded) === false || $decoded === [])) {
            $json = $decoded;
        }
        // The API echoes a request id we sent; the header is present on every
        // response, including the 413 and per-IP 429 that carry no body id.
        $rid = null;
        if (isset($json['request_id']) && is_string($json['request_id'])) {
            $rid = $json['request_id'];
        } elseif ($res->header('X-Request-Id') !== null) {
            $rid = $res->header('X-Request-Id');
        } else {
            $rid = $requestId;
        }
        $ra = trim((string) ($res->header('Retry-After') ?? ''));

        return [
            'status' => $res->status,
            'headers' => $res->headers,
            'body' => $res->body,
            'json' => $json,
            'requestId' => $rid,
            'attempts' => $attempt,
            'retryAfter' => preg_match('/^\d+$/', $ra) === 1 ? (int) $ra : 1,
        ];
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string, json: array<string, mixed>|null, requestId: ?string, attempts: int, retryAfter: int} $res
     */
    private function errorFor(array $res): ApiException
    {
        $json = $res['json'] ?? [];
        $status = $res['status'];
        $code = isset($json['error']) && is_string($json['error']) ? $json['error'] : "http_{$status}";
        $message = isset($json['message']) && is_string($json['message']) ? $json['message'] : null;
        $rid = $res['requestId'];
        $body = $res['body'];
        $attempts = $res['attempts'];

        switch ($status) {
            case 401:
                return new UnauthorizedException($status, $code, $message, $rid, $body, $attempts);
            case 422:
                $fields = [];
                foreach ((array) ($json['fields'] ?? []) as $k => $v) {
                    $fields[(string) $k] = (string) $v;
                }

                return new ValidationException($fields, $status, $code, $message, $rid, $body, $attempts);
            case 429:
                return new RateLimitedException($res['retryAfter'], $status, $code, $message, $rid, $body, $attempts);
            default:
                return new ApiException($status, $code, $message, $rid, $body, $attempts);
        }
    }
}
