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
TELEMETRY_LOGGER_ENABLED=true
TELEMETRY_LOGGER_ENDPOINT=https://your-microservice.com/api/logs
TELEMETRY_LOGGER_TOKEN=your-secret-bearer-token

# Queue (recommended for production)
TELEMETRY_LOGGER_QUEUE_ENABLED=true
TELEMETRY_LOGGER_QUEUE_NAME=telemetry
TELEMETRY_LOGGER_QUEUE_TRIES=3
TELEMETRY_LOGGER_QUEUE_BACKOFF=10

# Optional features
TELEMETRY_LOGGER_LOG_RESPONSES=false
TELEMETRY_LOGGER_INCLUDE_SESSION=false
TELEMETRY_LOGGER_SLOW_QUERIES=true
TELEMETRY_LOGGER_SLOW_QUERY_THRESHOLD=1000
TELEMETRY_LOGGER_LOG_JOBS=false
TELEMETRY_LOGGER_LOG_COMMANDS=false
```

---

## Payload Structure

Every log sent to your microservice is a JSON object with this shape:

### Request Log

```json
{
  "type": "request",
  "id": "uuid-v4",
  "timestamp": "2024-01-01T00:00:00.000Z",
  "duration_ms": 42.5,
  "request": {
    "method": "POST",
    "url": "https://app.com/api/login",
    "path": "api/login",
    "route_name": "login",
    "route_action": "App\\Http\\Controllers\\AuthController@login",
    "ip": "123.456.789.0",
    "ips": ["123.456.789.0"],
    "user_agent": "Mozilla/5.0 ...",
    "referer": null,
    "headers": {
      "content-type": "application/json",
      "authorization": "***REDACTED***"
    },
    "body": {
      "email": "user@example.com",
      "password": "***REDACTED***"
    },
    "query": {},
    "files": {}
  },
  "response": {
    "status_code": 200,
    "headers": {},
    "body": null
  },
  "user": {
    "id": 42,
    "email": "user@example.com",
    "name": "John Doe"
  },
  "session": {
    "id": "session-id-here"
  },
  "server": {
    "hostname": "app-server-1",
    "php": "8.2.0"
  },
  "meta": {
    "service": "my-app",
    "env": "production"
  }
}
```

### Exception Log

```json
{
  "type": "exception",
  "id": "uuid-v4",
  "timestamp": "...",
  "exception": {
    "class": "Illuminate\\Database\\QueryException",
    "message": "SQLSTATE[42S02]: Base table...",
    "code": 0,
    "file": "/app/routes/api.php",
    "line": 42,
    "trace": [...]
  },
  "request": { ... },
  "user": { ... }
}
```

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
            'id'       => $user->id,
            'email'    => $user->email,
            'role'     => $user->role,
            'tenant'   => $user->tenant_id,
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
    'queue' => ['telemetry'],
    'balance' => 'simple',
    'processes' => 1,
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
