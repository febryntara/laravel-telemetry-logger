# Laravel Telemetry Logger

[![Latest Version on Packagist](https://img.shields.io/packagist/v/febryntara/laravel-telemetry-logger.svg?style=flat-square)](https://packagist.org/packages/febryntara/laravel-telemetry-logger)
[![GitHub Tests](https://img.shields.io/github/actions/workflow/status/febryntara/laravel-telemetry-logger/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/febryntara/laravel-telemetry-logger/actions)
[![License](https://img.shields.io/packagist/l/febryntara/laravel-telemetry-logger.svg?style=flat-square)](LICENSE.md)

Comprehensive Laravel activity logger. Captures every HTTP request (including GET), session data, user identity, errors, slow queries, queue jobs, and artisan commands — with **automatic sensitive data filtering** — and forwards all logs asynchronously to your own logging microservice.

Designed for AI-assisted anomaly detection, security auditing, and production debugging.

---

## Features

- ✅ **All HTTP methods** — GET, POST, PUT, PATCH, DELETE, etc.
- ✅ **Request metadata** — method, URL, route name, action, IP, User-Agent, referer
- ✅ **Request body** — with deep recursive sanitization of sensitive fields
- ✅ **Request headers** — with masking of sensitive headers (Authorization, Cookie, etc.)
- ✅ **File upload metadata** — filename, MIME type, size (no content)
- ✅ **Response logging** — status code, headers, optional response body
- ✅ **Session data** — session ID, and optionally session contents
- ✅ **Authenticated user** — user ID, email, name (or custom resolver)
- ✅ **Exception logging** — full stack trace, previous exceptions
- ✅ **Slow query logging** — SQL, bindings, execution time (configurable threshold)
- ✅ **Queue job logging** — processed and failed jobs
- ✅ **Artisan command logging** — command name, exit code
- ✅ **Async dispatch** — uses Laravel Queue, never blocks response
- ✅ **Retry with backoff** — configurable retries if microservice is down
- ✅ **Single & adaptive send modes** — static one-by-one or auto-batch under load
- ✅ **Route exclusion** — skip routes like `telescope*`, `horizon*`, `_debugbar*`
- ✅ **Custom user resolver** — for non-standard auth systems

---

## Requirements

- PHP 8.1+
- Laravel 10.x or 11.x

---

## Installation

```bash
composer require febryntara/laravel-telemetry-logger
```

Publish the config file:

```bash
php artisan vendor:publish --tag=telemetry-logger-config
```

---

## Configuration

Add these to your `.env`:

```env
# ── Required ──────────────────────────────────────────────────────────────────
TELEMETRY_LOGGER_ENABLED=true
TELEMETRY_LOGGER_ENDPOINT=https://your-microservice.com/api
TELEMETRY_LOGGER_TOKEN=your-secret-token

# ── Send Mode ─────────────────────────────────────────────────────────────────
# "single" (default) — send immediately and synchronously, no queue needed
# "adaptive"         — use queue, auto-batch when queue is backed up
TELEMETRY_SEND_MODE=single

# ── Token Header ──────────────────────────────────────────────────────────────
# "Authorization" (default) → Authorization: Bearer <token>
# "X-API-Key"               → X-API-Key: <token>
TELEMETRY_LOGGER_TOKEN_HEADER=Authorization

# ── Host Name (optional) ──────────────────────────────────────────────────────
# Override the hostname sent in every log payload.
# Defaults to system hostname (gethostname()) if not set.
TELEMETRY_LOGGER_HOST=my-app-server

# ── Queue (only needed when TELEMETRY_SEND_MODE=adaptive) ─────────────────────
TELEMETRY_LOGGER_QUEUE_NAME=telemetry
TELEMETRY_LOGGER_QUEUE_TRIES=3
TELEMETRY_LOGGER_QUEUE_BACKOFF=10

# ── Adaptive Mode (only needed when TELEMETRY_SEND_MODE=adaptive) ─────────────
TELEMETRY_ADAPTIVE_THRESHOLD=10
TELEMETRY_ADAPTIVE_BATCH_SIZE=50

# ── Optional Features ─────────────────────────────────────────────────────────
TELEMETRY_LOGGER_LOG_RESPONSES=false
TELEMETRY_LOGGER_INCLUDE_SESSION=false
TELEMETRY_LOGGER_SLOW_QUERIES=false
TELEMETRY_LOGGER_SLOW_QUERY_THRESHOLD=1000
TELEMETRY_LOGGER_LOG_JOBS=false
TELEMETRY_LOGGER_LOG_COMMANDS=false
```

---

## Send Modes

The package supports two send modes, configurable via `TELEMETRY_SEND_MODE`.

### `single` (default)

Logs are sent **immediately and synchronously** to `POST /logs` — right when the request happens, before the response is returned. No queue involved, no cron needed, no delay.

```env
TELEMETRY_SEND_MODE=single
```

Best for: most use cases, shared hosting, anywhere you want real-time logs without setting up a queue worker.

> **Note:** each log adds a small HTTP round-trip to your response time (typically < 50ms on a local network). This is usually negligible but worth considering on very high-traffic applications.

### `adaptive`

Logs are dispatched **asynchronously via Laravel Queue**. When the queue is healthy, logs go out one-by-one to `POST /logs`. When the queue starts backing up (depth ≥ threshold), payloads are accumulated in a cache buffer and flushed together to `POST /logs/batch` — reducing HTTP round-trips under load.

```env
TELEMETRY_SEND_MODE=adaptive
TELEMETRY_ADAPTIVE_THRESHOLD=10   # queue depth that triggers batch mode
TELEMETRY_ADAPTIVE_BATCH_SIZE=50  # payloads per batch flush
```

Best for: high-traffic production apps with a dedicated queue worker (Supervisor/Horizon).

> **Note:** `adaptive` mode requires a running queue worker and your microservice to support `POST /logs/batch` with body `{ "logs": [...] }`. If your microservice is down, queued jobs are retained and delivered automatically once it recovers.

**How adaptive mode handles failures:** if a batch request fails, all payloads are pushed back into the cache buffer so nothing is lost. The job is then re-queued with the configured backoff and retries automatically.

---

## Host Name

By default, the `host` field in every log payload uses the system hostname (`gethostname()`). On shared hosting this is often an unreadable server name like `sg-nme-web621.main-hosting.eu`.

You can override it with a human-readable name via `.env`:

```env
TELEMETRY_LOGGER_HOST=devloka-web
```

This is especially useful when you want the `host` field to match the `source_tag` name configured in your syslog API key.

---

## Token Header & Authentication

The package supports any authentication header your microservice uses. Set it via `TELEMETRY_LOGGER_TOKEN_HEADER`:

```env
# Default — sends Authorization: Bearer <token>
TELEMETRY_LOGGER_TOKEN_HEADER=Authorization

# For microservices that use X-API-Key
TELEMETRY_LOGGER_TOKEN_HEADER=X-API-Key

# Any custom header
TELEMETRY_LOGGER_TOKEN_HEADER=X-Internal-Secret
```

### Automatic Token Redaction

Whatever header name you configure as `TELEMETRY_LOGGER_TOKEN_HEADER`, the package **automatically redacts it** from all logged payloads — even if it is not listed in `sensitive_headers`. This prevents your outbound API token from ever appearing in the logs sent to your microservice.

For example, if you set `TELEMETRY_LOGGER_TOKEN_HEADER=X-API-Key`, any incoming request that contains an `X-API-Key` header will have its value replaced with `***REDACTED***` in the log, regardless of what is in the `sensitive_headers` config.

---

## Payload Structure

Every log sent to your microservice follows this syslog-compatible format:

```json
{
  "timestamp": "2024-01-01T00:00:00+00:00",
  "host":      "app-server-1",
  "service":   "my-app",
  "severity":  "info",
  "message":   "[INFO] POST api/login — 42.5 ms | user:12 | ip:123.4.5.6 | detail:{...}"
}
```

The `severity` field maps automatically from HTTP status codes:

| Status      | Severity  |
|-------------|-----------|
| 2xx         | `info`    |
| 4xx         | `warning` |
| 5xx         | `error`   |
| Exceptions  | `error`   |
| Slow queries| `warning` |
| Events      | as passed |

The `message` field contains a human-readable summary followed by the full telemetry detail JSON-encoded inline, including:

- `duration_ms`, `method`, `url`, `route_name`, `route_action`
- `ip`, `user_agent`, `referer`
- `headers` — with sensitive headers masked as `***REDACTED***`
- `body` — with sensitive fields recursively redacted
- `query`, `files`
- `response` — status code, headers, optional body
- `user` — id, email, name
- `session` — session ID (and optionally session contents)

---

## Custom User Resolver

For non-standard auth (JWT, API keys, multi-guard), implement the contract:

```php
use Febryntara\TelemetryLogger\Contracts\UserResolverContract;
use Illuminate\Http\Request;

class MyUserResolver implements UserResolverContract
{
    public function resolve(Request $request): ?array
    {
        $user = auth('api')->user();
        if (! $user) return null;

        return [
            'id'     => $user->id,
            'email'  => $user->email,
            'role'   => $user->role,
            'tenant' => $user->tenant_id,
        ];
    }
}
```

Then register it in `config/telemetry-logger.php`:

```php
'user_resolver' => \App\Support\MyUserResolver::class,
```

---

## Manual Logging

Use the Facade to log custom events from anywhere in your application:

```php
use Febryntara\TelemetryLogger\Facades\TelemetryLogger;

// Log a custom event
TelemetryLogger::logEvent('payment.completed', [
    'order_id' => $order->id,
    'amount'   => $order->total,
], 'info');

// Log an exception manually
try {
    // ...
} catch (\Throwable $e) {
    TelemetryLogger::logException($e, request());
    throw $e;
}
```

---

## Excluding Routes

Add patterns in `config/telemetry-logger.php`:

```php
'exclude_routes' => [
    'telescope*',
    'horizon*',
    '_debugbar*',
    'health',
    'api/ping',
    'livewire/update',
],
```

---

## Queue Setup

For production, ensure you have a queue worker running:

```bash
php artisan queue:work --queue=telemetry,default
```

Or with Laravel Horizon, add to `config/horizon.php`:

```php
'telemetry' => [
    'connection' => 'redis',
    'queue'      => ['telemetry'],
    'balance'    => 'simple',
    'processes'  => 1,
],
```

---

## Testing

```bash
composer test
```

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## License

MIT. See [LICENSE.md](LICENSE.md).
