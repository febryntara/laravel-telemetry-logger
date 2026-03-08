<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable Telemetry Logger
    |--------------------------------------------------------------------------
    */
    'enabled' => env('TELEMETRY_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Microservice Endpoint
    |--------------------------------------------------------------------------
    | URL of your logging microservice that will receive log payloads.
    */
    'endpoint' => env('TELEMETRY_LOGGER_ENDPOINT', null),

    /*
    |--------------------------------------------------------------------------
    | Endpoint Auth Token
    |--------------------------------------------------------------------------
    | Bearer token sent in Authorization header to your microservice.
    */
    'token' => env('TELEMETRY_LOGGER_TOKEN', null),

    /*
    |--------------------------------------------------------------------------
    | Token Header Name
    |--------------------------------------------------------------------------
    | The header used to send the API token to your microservice.
    |
    | "Authorization" — sends as: Authorization: Bearer <token>  (default)
    | "X-API-Key"     — sends as: X-API-Key: <token>
    | Any custom header name is also supported.
    */
    'token_header' => env('TELEMETRY_LOGGER_TOKEN_HEADER', 'Authorization'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => env('TELEMETRY_LOGGER_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Send Mode
    |--------------------------------------------------------------------------
    | Controls how logs are dispatched to the microservice endpoint.
    |
    | "single"   — (default) Every log is sent individually to POST /logs.
    |              Simple, predictable, works with any microservice.
    |
    | "adaptive" — Monitors queue depth. When the queue is healthy, logs are
    |              sent one-by-one to POST /logs. When the queue backs up
    |              (depth >= batch_threshold), payloads are accumulated in a
    |              cache buffer and flushed together to POST /logs/batch,
    |              reducing HTTP round-trips under load.
    |
    | Note: "adaptive" requires your microservice to support POST /logs/batch
    | with body: { "logs": [...] }
    */
    'send_mode' => env('TELEMETRY_SEND_MODE', 'single'),

    /*
    |--------------------------------------------------------------------------
    | Adaptive Mode Settings
    |--------------------------------------------------------------------------
    | Only used when send_mode = "adaptive".
    |
    | batch_threshold — queue depth that triggers batch mode.
    | batch_size      — number of payloads to accumulate before flushing.
    */
    'adaptive' => [
        'batch_threshold' => env('TELEMETRY_ADAPTIVE_THRESHOLD', 10),
        'batch_size'      => env('TELEMETRY_ADAPTIVE_BATCH_SIZE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    | Logs are dispatched asynchronously via Laravel Queue to avoid
    | blocking the main request/response cycle.
    */
    'queue' => [
        'enabled'     => env('TELEMETRY_LOGGER_QUEUE_ENABLED', true),
        'connection'  => env('TELEMETRY_LOGGER_QUEUE_CONNECTION', null), // null = default
        'name'        => env('TELEMETRY_LOGGER_QUEUE_NAME', 'telemetry'),
        'tries'       => env('TELEMETRY_LOGGER_QUEUE_TRIES', 3),
        'backoff'     => env('TELEMETRY_LOGGER_QUEUE_BACKOFF', 10), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | What to Log
    |--------------------------------------------------------------------------
    */
    'log' => [
        // Log all HTTP requests (including GET)
        'requests'   => true,

        // Log HTTP responses (body can be large, enable with care)
        'responses'  => env('TELEMETRY_LOGGER_LOG_RESPONSES', false),

        // Log unhandled Laravel exceptions
        'exceptions' => true,

        // Log slow queries (set threshold in ms, 0 to disable)
        'slow_queries'          => env('TELEMETRY_LOGGER_SLOW_QUERIES', 0),
        'slow_query_threshold'  => env('TELEMETRY_LOGGER_SLOW_QUERY_THRESHOLD', 1000),

        // Log scheduled command execution
        'commands' => env('TELEMETRY_LOGGER_LOG_COMMANDS', false),

        // Log queue job execution
        'jobs' => env('TELEMETRY_LOGGER_LOG_JOBS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes to Exclude
    |--------------------------------------------------------------------------
    | URI prefixes or exact paths that should NOT be logged.
    */
    'exclude_routes' => [
        'telescope*',
        'horizon*',
        '_debugbar*',
        'livewire/update',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Fields — Body / Input
    |--------------------------------------------------------------------------
    | These keys will be replaced with "***REDACTED***" in the logged payload.
    */
    'sensitive_fields' => [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'secret',
        'token',
        'api_key',
        'api_secret',
        'access_token',
        'refresh_token',
        'authorization',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
        'pin',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Headers
    |--------------------------------------------------------------------------
    | These request headers will be masked in logs.
    */
    'sensitive_headers' => [
        'authorization',
        'x-api-key',
        'x-auth-token',
        'cookie',
        'set-cookie',
        'x-csrf-token',
        'x-xsrf-token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max Body Size (bytes)
    |--------------------------------------------------------------------------
    | Truncate request/response body if it exceeds this limit.
    | Set to 0 to disable truncation.
    */
    'max_body_size' => env('TELEMETRY_LOGGER_MAX_BODY_SIZE', 10240), // 10 KB

    /*
    |--------------------------------------------------------------------------
    | Include Session Data
    |--------------------------------------------------------------------------
    */
    'include_session' => env('TELEMETRY_LOGGER_INCLUDE_SESSION', false),

    /*
    |--------------------------------------------------------------------------
    | Session Keys to Exclude
    |--------------------------------------------------------------------------
    */
    'exclude_session_keys' => [
        '_token',
        'password',
        'login_web_*',
    ],

    /*
    |--------------------------------------------------------------------------
    | User Resolver
    |--------------------------------------------------------------------------
    | Callable to extract user info from the request.
    | Receives \Illuminate\Http\Request, should return array|null.
    */
    'user_resolver' => null, // e.g. \App\Support\TelemetryUserResolver::class

    /*
    |--------------------------------------------------------------------------
    | Additional Metadata
    |--------------------------------------------------------------------------
    | Static key-value pairs appended to every log payload.
    | Useful for identifying the service/environment.
    */
    'metadata' => [
        'service' => env('APP_NAME', 'laravel'),
        'env'     => env('APP_ENV', 'production'),
    ],

];
