# Contributing

Thanks for helping partners send leads correctly.

## Ground rules

- **The API contract is the source of truth**, not this package. If the package and
  <https://intake.hellojade.ai/api/openapi.json> disagree, the package is wrong — open an
  issue with the request, the response, and the `request_id`.
- **Never post real-looking leads to production while developing.** The test suite runs
  against a local `php -S` stub. To exercise the live endpoint, use the key check
  (`Client::checkKey()`, which stores nothing) or ask hellojade for a sandbox key.
- **Never commit an API key.** `grep -r "hj_" .` before you push; CI does not know your
  key and cannot catch it for you.
- **No runtime dependencies.** The client is `ext-curl` and `ext-json` only, on purpose.
  Development dependencies (PHPUnit) are fine, and `psr/http-client` stays a `suggest`.
- American English in code, comments and docs.

## Setup

Requires **PHP ≥ 8.1** with `ext-curl` and `ext-json`, and
[Composer](https://getcomposer.org/).

```sh
git clone https://github.com/hellojade-ai/leads-php
cd leads-php
composer install
vendor/bin/phpunit
composer validate --strict
```

`composer.lock` is gitignored on purpose: this is a library, so the lock file would only
pin *our* development environment and would fight consumers' resolutions.

## Making a change

1. Branch from `main`.
2. Add or update a test in `tests/` first. Every documented status code has a test; a
   behavior change without one will not be merged.
3. Keep `README.md` and `CHANGELOG.md` current in the same pull request.
4. Open a pull request. CI runs the suite on every supported PHP version.

### Notes on the test stub

`tests/StubServer.php` starts `php -S` with a scripted response queue on a temporary
directory, and hands it `PHP_CLI_SERVER_WORKERS=4`. That is load-bearing: `php -S` is
single-worker by default, and the timeout test fires its retry while the first (delayed)
response is still being held open. With one worker the retry queues behind it, drains the
scripted queue, and the test fails on an unscripted response.

## Releasing

Releases are GitHub tags, not Packagist (see the README). Bump `Version::STRING` in
`src/Version.php`, add a CHANGELOG entry, commit, then:

```sh
git tag -a vX.Y.Z -m "hellojade/intake vX.Y.Z"
git push origin main --tags
```

Consumers pin the package through a `vcs` repository entry pointing at this repo, so a
pushed tag is the whole release.
