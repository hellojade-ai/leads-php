# hellojade/intake (PHP)

PHP client for the **hellojade Partner Intake API** — one `POST` that hands a lead to a
hellojade customer, durably, with idempotency and sane retries built in.

- PHP ≥ 8.1, `ext-curl` and `ext-json` only, **no runtime dependencies**
- Typed `Lead`, `Accepted`, `ApiException`, `ValidationException::$fields`,
  `RateLimitedException::$retryAfter`
- Retry policy that follows the API's rules: 5xx and timeouts back off, `429` honors
  `Retry-After` without spending a delivery attempt, every other 4xx is final
- The API key never appears in an exception message, `var_dump()` or a log line (tested)
- Optional `Psr18Transport` if you would rather route requests through your own PSR-18 client

| | |
|---|---|
| API reference and live playground | <https://intake.hellojade.ai/api> |
| OpenAPI 3.0 contract | <https://intake.hellojade.ai/api/openapi.json> |
| Integration brief (the eight rules) | <https://intake.hellojade.ai/api/INTEGRATION.md> |
| Becoming a lead provider | <https://hellojade.ai/developers/provide-leads> |
| Other kits | [Go](https://github.com/hellojade-ai/leads-go) · [Node](https://github.com/hellojade-ai/leads-node) · [Python](https://github.com/hellojade-ai/leads-python) · [Ruby](https://github.com/hellojade-ai/leads-ruby) · [Java](https://github.com/hellojade-ai/leads-java) · [.NET](https://github.com/hellojade-ai/leads-dotnet) |

## Install

The package is distributed from GitHub, **not Packagist**, so add it as a VCS repository
in your `composer.json` first:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/hellojade-ai/leads-php" }
    ]
}
```

Then:

```sh
composer require hellojade/intake:^0.1
```

Composer resolves `^0.1` against the repository's `v*` tags. The package ships PSR-4
autoloading under `HelloJade\Intake\`, so `vendor/autoload.php` is all you need:

```php
require __DIR__ . '/vendor/autoload.php';

use HelloJade\Intake\Client;
```

## Quickstart

### 1. Prove the key first — it stores nothing

The API authenticates *before* it validates, so an empty body sent with a valid key comes
back `422` and nothing is stored, delivered or emailed. Do this before writing any code and
again on launch day.

```php
$client = new Client(apiKey: getenv('HELLOJADE_API_KEY'));

$client->checkKey();   // true  (422: key valid, nothing stored)
                       // false (401: missing, mistyped, revoked, or wrong host)
```

### 2. Submit a lead

```php
use HelloJade\Intake\Lead;

$lead = new Lead(
    firstName:       'Dana',
    lastName:        'Whitfield',
    phone:           '(630) 555-0142',
    email:           'dana.whitfield@example.com',
    streetAddress:   '418 N Maple St',
    city:            'Naperville',
    state:           'IL',
    zip:             '60540',
    country:         'US',
    projectArea:     'roof',                 // live list: $client->vocabulary()
    projectService:  'replacement',          // replacement | repair | remodel | maintain
    projectMaterial: 'asphalt shingle',
    projectDetails:  'Hail damage on the south slope, insurance claim already filed.',
    externalId:      'A-99812',
    cost:            555.55,                 // omit if there is no charge — never send 0
    extra:           ['partner_job_id' => 'XZ-1'], // unmodeled fields are preserved, never rejected
);

$result = $client->submitLead($lead, 'acme-leads:A-99812', 'acme-leads/A-99812');

$result->eventId;       // "evt_0198f2c1a4b00000a3d19f4c2b7e" — store this against your lead
$result->status;        // "accepted" (202) or "duplicate" (200) — both are success
$result->source;        // your key's registered label
$result->flags;         // [] — non-fatal observations, never errors
$result->isAccepted();  // bool
$result->isDuplicate(); // bool
```

Only `firstName`, `lastName` and `phone` are required. Send everything you have and
nothing you do not — a `null` field is omitted from the JSON, never sent as `null` or a
placeholder. Do **not** send `source`: it comes from your API key, and `Lead` throws if you
put it in `extra`.

`submitLead()` also accepts a plain wire-shaped array (`['first_name' => ..., ...]`), and
`Lead::fromArray()` routes unmodeled keys into `extra` for you.

### 3. Handle every outcome

```php
use HelloJade\Intake\Exception\{
    ApiException, RateLimitedException, TransportException, UnauthorizedException, ValidationException
};

try {
    $result = $client->submitLead($lead, "acme-leads:{$leadId}");
} catch (ValidationException $e) {   // 422
    $e->fields;      // ['first_name' => 'required', 'phone' => 'required'] — ALL failing fields at once
    $e->requestId;
} catch (UnauthorizedException $e) { // 401 — a configuration problem, not a retry
} catch (RateLimitedException $e) {  // 429, after the wait budget is spent
    $e->retryAfter;
} catch (ApiException $e) {          // 400, 413, or a 5xx after retries
    $e->status; $e->apiCode; $e->requestId;
} catch (TransportException $e) {    // no response after retries
    $e->attempts;
}
```

Catch order matters: `UnauthorizedException`, `ValidationException` and
`RateLimitedException` all extend `ApiException`, and every one of the five extends
`IntakeException`, so put the specific classes first.

Full, runnable versions are in [`examples/`](examples/).

## Client options

```php
use HelloJade\Intake\{Client, RetryPolicy};

$client = new Client(
    apiKey:      getenv('HELLOJADE_API_KEY'),    // required; from env or a secret store, never source
    baseUrl:     'https://intake.hellojade.ai',  // HTTPS only — there is no listener on port 80
    timeout:     20.0,                           // seconds, connect and total; the API bounds its handler at 20 s
    userAgent:   'acme-leads-sync/2.3',          // appended to hellojade-intake-php/<version>
    retryPolicy: new RetryPolicy(
        maxAttempts:       5,     // delivery attempts (a 429 does not consume one)
        maxRateLimitWaits: 10,    // consecutive 429s to wait out before throwing RateLimitedException
        baseDelay:         1.0,   // backoff = min(base * 2**(n-1), max) + rand * jitter
        maxDelay:          30.0,
        jitter:            0.5,
    ),
    transport:   null,   // defaults to CurlTransport; see Psr18Transport below
    sleeper:     null,   // callable(float $seconds): void — injectable for tests
);
```

| option | default | notes |
|---|---|---|
| `apiKey` | *(required)* | trimmed; an empty key throws `InvalidArgumentException` |
| `baseUrl` | `https://intake.hellojade.ai` | must be an `http(s)` URL with a host |
| `timeout` | `20.0` | seconds, applied to connect and to the whole transfer |
| `userAgent` | `hellojade-intake-php/0.1.0` | your string is appended, not substituted |
| `retryPolicy` | `new RetryPolicy()` | `RetryPolicy::none()` makes exactly one attempt |
| `transport` | `new CurlTransport()` | any `TransportInterface`; `Psr18Transport` ships with the package |
| `sleeper` | `usleep()` | receives seconds to wait; replace it in tests |

`RetryPolicy::none()` makes exactly one attempt for callers that run their own loop.

Reading the configuration back: `getBaseUrl()`, `getTimeout()`, `getUserAgent()`,
`getRetryPolicy()`.

### Using your own HTTP client

```php
use HelloJade\Intake\Transport\Psr18Transport;

$client = new Client(
    apiKey: getenv('HELLOJADE_API_KEY'),
    transport: new Psr18Transport($psr18Client, $requestFactory, $streamFactory),
);
```

`psr/http-client` and `psr/http-factory` are `suggest`ed, not required — the adapter is
duck-typed so those interfaces only need to exist when you actually use this class. The
per-request timeout becomes your PSR-18 client's responsibility.

## Client surface

| method | HTTP | returns |
|---|---|---|
| `checkKey(?string $requestId = null)` | `POST /v1/intake` with `{}` | `true` on 422, `false` on 401 |
| `submitLead(Lead\|array $lead, string $idempotencyKey, ?string $requestId = null)` | `POST /v1/intake` | `Accepted` on 202 or 200 |
| `vocabulary(?string $requestId = null)` | `GET /v1/vocabulary` (unauthenticated) | `Vocabulary` — `project_area` terms with status, `project_service` enum, `required` |
| `health(?string $requestId = null)` | `GET /healthz` (unauthenticated) | `Health` for both 200 and 503 |

Value objects:

| class | members |
|---|---|
| `Accepted` | `$eventId`, `$status`, `$receivedAt`, `$flags`, `$source`, `$requestId`, `$httpStatus`, `isAccepted()`, `isDuplicate()` |
| `Vocabulary` | `$projectArea` (`VocabularyTerm[]`), `$projectService`, `$required`, `areas()`, `hasArea(string)` |
| `VocabularyTerm` | `$area`, `$status` |
| `Health` | `$ok`, `$storeWritable`, `$pending`, `$dead`, `$oldestPendingAgeS`, `$httpStatus` |
| `Lead` | every modeled field as a promoted property, plus `setExtra()`, `getExtra()`, `toArray()`, `Lead::fromArray()`, `Lead::FIELDS`, `Lead::PROJECT_SERVICES`, `Lead::RESERVED_KEYS` |

## Errors

| HTTP | API `error` | thrown | retried? | what to do |
|---|---|---|---|---|
| 202 | — | *(returns `Accepted`, status `accepted`)* | — | store `eventId`; done |
| 200 | — | *(returns `Accepted`, status `duplicate`)* | — | same `eventId` as before; done |
| 400 | `invalid_json` | `ApiException` | no | log, alert |
| 401 | `unauthorized` | `UnauthorizedException` | no | fix the key / host; see the key check |
| 413 | `body_too_large` | `ApiException` | no | body over 64 KiB — trim `projectDetails` |
| 422 | `validation_failed` | `ValidationException` (`$fields`) | no | fix every listed field |
| 429 | `rate_limited` | `RateLimitedException` (`$retryAfter`) only after the wait budget | yes — waits `max(Retry-After, backoff)` | usually nothing; back off further if sustained |
| 503 | `not_accepting` | `ApiException` after `maxAttempts` | yes — exponential backoff | this is hellojade's side |
| other 5xx | — | `ApiException` after `maxAttempts` | yes | |
| no response | — | `TransportException` after `maxAttempts` | yes | check `https://`, egress, DNS |

Every `ApiException` carries `$status`, `$apiCode`, `$requestId`, `$body`, `$attempts` and
`isRetryable()`. `$requestId` comes from the response body, or from the `X-Request-Id`
header when the body has none. Quote it — or the `eventId` — in any support conversation.
Never the key.

## Retry and idempotency semantics

1. **Always pass an idempotency key, and make it your own stable id for the lead**,
   namespaced to you: `acme-leads:1234`, not `1234`, not a timestamp, not a fresh UUID per
   attempt. Dedupe is scoped to the *tenant*, so a bare `1234` can collide with another
   source's lead and yours is silently never stored. The client refuses an empty key.
2. A repeat of an accepted key returns `200` with the **original** `eventId` and status
   `duplicate`. That is success — it is what a retry is supposed to produce.
3. **Retries are automatic** for transport errors (DNS, connect, TLS, timeout) and 5xx, with
   exponential backoff plus jitter, up to `maxAttempts`. The same `Idempotency-Key` goes out
   on every attempt, so a request that actually arrived cannot create a duplicate.
4. **`429` waits `max(Retry-After, backoff(n))`** and does not consume a delivery attempt.
   `Retry-After` is a floor, not a strategy, so the wait grows with consecutive 429s.
5. **Any other 4xx is never retried.** A `422` means the body needs fixing; a `401` means the
   configuration does.
6. **Flags are not errors.** `phone_unnormalized`, `project_area_unknown`,
   `project_service_unknown`, `email_shape_suspect`, `extra_fields_preserved` and
   `country_unrecognized` arrive on a *successful* response, in `Accepted::$flags`. Read
   them, do not retry on them.
7. A `422` does not consume the `Idempotency-Key`; send the same key again with a fixed body.
8. `X-Request-Id`: pass your own correlation id (≤ 64 chars) as the last argument, or let the
   client generate one. It is echoed in the response header and in any error body.

## Development

```sh
composer install
vendor/bin/phpunit          # PHPUnit against a local php -S stub — nothing touches the real API
composer validate --strict
```

The suite scripts every documented status code (200, 202, 400, 401, 413, 422, 429, 503,
plus timeouts and connection refusals) and asserts the exact backoff sequence, the
`Retry-After` handling, that a 429 does not consume an attempt, that a timed-out request is
retried under the *same* `Idempotency-Key`, and that the API key never appears in an
exception message.

The stub runs with `PHP_CLI_SERVER_WORKERS=4` because `php -S` is single-worker by default:
the timeout test fires its retry while the first response is still being held open, so the
stub has to be able to answer both at once.

## Releasing

Tags on GitHub only — this package is **not** published to Packagist. Bump
`src/Version.php`, add a `CHANGELOG.md` entry, then:

```sh
git tag -a vX.Y.Z -m "hellojade/intake vX.Y.Z"
git push origin main --tags
```

See [CONTRIBUTING.md](CONTRIBUTING.md#releasing).

## License

[MIT](LICENSE) © hellojade
