# Security

## Reporting a vulnerability

Email **security@hellojade.ai**. Please include the package version, a description, and
reproduction steps. Do not open a public issue for a security report. You will get an
acknowledgement within two business days.

## Your API key

- The key is a bearer credential. Keep it in an environment variable or a secret store —
  never in source, a URL, a log line, or a support ticket.
- This package never writes the key to any exception message, `var_dump()` / `print_r()`
  output, or log. `Client::__debugInfo()` redacts it, and the test suite asserts the key
  does not appear in any thrown message.
- If a key is exposed, contact the person at hellojade who issued it and ask for a
  rotation. Keys are stored hashed on the server; a lost key is rotated, never recovered.

## Transport

- The API is HTTPS only (TLS 1.2+, HTTP/2). There is no listener on port 80.
- `CurlTransport` sets `CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST` and does not
  follow redirects. Do not disable any of that.
- If you supply your own `Psr18Transport`, certificate verification is your HTTP client's
  responsibility. Leave it on.

## Supported versions

Security fixes land on the latest minor release. PHP 8.1 and newer are supported.
