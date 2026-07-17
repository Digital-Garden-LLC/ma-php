# ma-php

PHP SDK for miniargus, a self-hosted observability platform — **PHP 5.6
and up**. Two independent packages your application uses to talk to the
local miniargus agent, both pure PHP: no compiled extension, no PHP 7+
language features, no dependency on each other.

```sh
composer require digital-garden-llc/ma-php
```

## Why this exists instead of the OpenTelemetry PHP SDK

OTel's own PHP SDK requires PHP 8.1+. If you're already on 8.1+, use that
— it's the more complete, standards-based option, and miniargus's
ingestion API accepts OTLP/HTTP JSON directly (point its exporter at your
deployment's `POST /v1/ingest/traces`, no agent involved). This package
exists for everyone else: PHP 5.6 through 8.0, which describes a lot of
real production code that can't be upgraded on demand.

## tracing — HTTP request tracing

There's no PHP-wide equivalent of a `Middleware` wrapping one shared
`http.Handler` chain — PHP has no single request-handling interface every
framework agrees on, and no persistent process to wrap once (a request is
typically one script execution, start to finish). Instead, construct a
`Tracer` as early as possible in the request and finish its root span at
the very end:

```php
use Miniargus\Tracing\Tracer;

$tracer = new Tracer('service-name'); // service name -- every app should set its own

$root = $tracer->startRequestSpan(); // reads method/path from $_SERVER by default

register_shutdown_function(function () use ($root) {
    $root->setHttpStatus(http_response_code());
    $root->finish();
});

// ... handle the request ...
```

`startRequestSpan()` reads an incoming `traceparent` header (W3C Trace
Context) from `$_SERVER['HTTP_TRACEPARENT']` if present, so a request
arriving from an already-traced caller (a Go service, another PHP app,
anything OTel-compatible) continues that trace instead of starting a new
one — and the reverse works too: this SDK's spans carry the same
`trace_id`/`span_id`/`parent_id` shape, so a PHP monolith calling into a
Go microservice (or vice versa) produces one connected trace end to end.

`Tracer`'s third constructor argument opts into capturing the request's
raw query string as the root span's `query_string` tag:

```php
$tracer = new Tracer('service-name', Tracer::DEFAULT_AGENT_ADDR, true);
```

**Off by default** — query strings routinely carry session tokens, emails,
or other PII that `path` was deliberately kept free of. Suspicious-looking
values (keys matching `password`/`token`/`secret`/`key`/`auth`/`session`/
`credential`/`signature`) are redacted to `<redacted>` before the span
ever leaves this process, and the captured value is truncated to 2048
bytes. This client-side redaction is best-effort minimization, not the
safety boundary — miniargus's own ingestion API re-applies the same
redaction server-side regardless of what this SDK sends. See miniargus's
SETUP.md Step 5 for the full rationale.

### Zero application code changes via `auto_prepend_file`

The closest thing to automatic instrumentation without a compiled
extension: point `php.ini`'s `auto_prepend_file` at a bootstrap script
containing the snippet above. It runs before every request PHP handles,
with no change to any of your application's own files:

```ini
; php.ini
auto_prepend_file = /path/to/miniargus-bootstrap.php
```

### Child spans

Nest a child span under whatever's currently active — a DB query, a
downstream HTTP call — with `startSpan()`. PHP has no `defer`, so
`finish()` belongs in a `finally` block:

```php
$span = $tracer->startSpan('db.query');
try {
    $rows = $db->query('SELECT * FROM orders WHERE id = ?', array($id));
} catch (\Exception $e) {
    $span->setError($e); // no-op if you don't call it, safe to call on any Exception
    throw $e;
} finally {
    $span->finish(); // idempotent -- safe even if something else already finished it
}
```

`startSpan()` nests under the request's root span automatically (it tracks
"current span" internally); called with nothing active — a CLI script, a
queue worker — it just starts a new root of its own rather than erroring.

`$span->setTag($key, $value)` attaches an arbitrary tag.

## events — custom application events

```php
use Miniargus\Events\Client;

$client = new Client('/tmp/miniargus-agent.sock'); // match the agent's --socket flag

$client->emit('order.completed', array('plan' => 'pro'), array('order_id' => '1234'));
```

Unlike ma-go's events client (a persistent background goroutine draining a
queue), PHP's per-request process model has nothing to run that queue in.
Each `emit()` call is a short, synchronous connect-write-close with a
50ms default timeout — bounded, not truly non-blocking the way the Go SDK
is, but fast enough not to matter, and it never throws: a down or slow
agent must never break the calling request.

## PHP version support

Floor is PHP 5.6. Verified (via `smoke.php`, see below) against 5.6, 7.4,
and 8.3 — the same source runs unmodified across that whole range, no
version-conditional code paths. Notable compatibility choices, in case you
need to know why something looks the way it does:
- ID generation prefers `random_bytes()` (7.0+), falls back to
  `openssl_random_pseudo_bytes()`, then `mt_rand()` if neither is
  available -- trace/span IDs are correlation identifiers, not secrets, so
  the weak fallback is an acceptable last resort, not a security issue.
- No scalar type hints or return types anywhere (both PHP 7.0+ only).
- Timestamps are formatted to millisecond precision by hand
  (`gmdate` + manual fractional-second padding) instead of relying on
  anything newer than what's been in PHP since the beginning.

## Testing

```sh
composer install
vendor/bin/phpunit
```

`composer.json` pins `config.platform.php` to `5.6.40` so dependency
resolution matches the floor this package targets, regardless of which
PHP version you're actually running Composer under (Composer 2.x itself
requires PHP 7.2.5+ to run at all -- if your *target* is genuinely PHP
5.6, use Composer 1.x, or skip Composer entirely and `require` the four
files in `src/` directly, no autoloader needed).

`smoke.php` is a standalone, dependency-free functional check (no
PHPUnit) for verifying against a specific interpreter directly:
```sh
docker run --rm -v "$PWD":/app -w /app php:5.6-cli php smoke.php
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php smoke.php
```

## Versioning

Pre-1.0: the API may still change. Pin a specific commit or tag if you
need stability.

## License

Apache 2.0 — see [LICENSE](LICENSE).
