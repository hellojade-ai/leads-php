# Changelog

All notable changes to this package are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package follows
[Semantic Versioning](https://semver.org/).

## [0.1.0] — 2026-09-03

### Added

- `HelloJade\Intake\Client` with `checkKey()`, `submitLead()`, `vocabulary()` and
  `health()`, plus `getBaseUrl()`, `getTimeout()`, `getUserAgent()` and
  `getRetryPolicy()`. `__debugInfo()` redacts the API key.
- `Lead` value object with every modeled field as a promoted constructor property,
  top-level `extra` passthrough, `Lead::fromArray()` for wire-shaped arrays, and guards
  against the reserved `source` / `extra` keys and against `extra` colliding with a
  modeled field. Null fields are omitted from the JSON, never sent as `null`.
- `Accepted`, `Vocabulary`, `VocabularyTerm` and `Health` response objects.
- `IntakeException` base, `ApiException` (`$status`, `$apiCode`, `$requestId`, `$body`,
  `$attempts`, `isRetryable()`), `UnauthorizedException`, `ValidationException`
  (with `$fields`), `RateLimitedException` (with `$retryAfter`) and `TransportException`
  (with `$attempts`).
- `RetryPolicy`: exponential backoff with jitter on 5xx and transport errors,
  `Retry-After`-aware waits on 429 that do not consume a delivery attempt, no retry on
  any other 4xx, and `RetryPolicy::none()` for callers running their own loop.
- `Transport\TransportInterface` with two implementations: `CurlTransport` (the default —
  ext-curl, certificate verification on, HTTP/2 when available) and `Psr18Transport`, a
  duck-typed adapter for any PSR-18 client.
- PHPUnit suite against a local `php -S` stub covering every documented status code, the
  exact backoff sequence, retry-under-the-same-idempotency-key on a timeout, and that the
  API key never reaches an exception message.
- CI on PHP 8.1, 8.2, 8.3 and 8.4.

[0.1.0]: https://github.com/hellojade-ai/leads-php/releases/tag/v0.1.0
