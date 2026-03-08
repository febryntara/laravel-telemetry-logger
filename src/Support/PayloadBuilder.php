<?php

namespace Febryntara\TelemetryLogger\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PayloadBuilder
{
    /**
     * Build syslog-compatible payload for a single HTTP request.
     *
     * Maps to POST /logs — required fields: host, service, severity, message.
     * All rich telemetry data (request detail, user, session, etc.) is
     * serialized as JSON inside the `message` field.
     */
    public static function fromRequest(
        Request $request,
        $response = null,
        float $durationMs = 0,
        array $config = []
    ): array {
        $statusCode = $response instanceof Response ? $response->getStatusCode() : null;
        $severity   = self::severityFromStatus($statusCode);
        $meta       = $config['metadata'] ?? [];

        $detail = [
            'duration_ms'  => round($durationMs, 2),
            'method'       => $request->method(),
            'url'          => $request->fullUrl(),
            'route_name'   => optional($request->route())->getName(),
            'route_action' => optional($request->route())->getActionName(),
            'ip'           => $request->ip(),
            'user_agent'   => $request->userAgent(),
            'referer'      => $request->headers->get('referer'),
            'headers'      => self::sanitizeHeaders($request->headers->all(), $config),
            'body'         => self::sanitizeBody($request->except([]), $config),
            'query'        => $request->query->all(),
            'files'        => self::extractFiles($request),
            'response'     => $response ? self::extractResponse($response, $config) : null,
            'user'         => self::resolveUser($request, $config),
            'session'      => self::extractSession($request, $config),
        ];

        return [
            'timestamp' => now()->toIso8601String(),
            'host'      => gethostname(),
            'service'   => $meta['service'] ?? config('app.name', 'laravel'),
            'severity'  => $severity,
            'message'   => sprintf(
                '[%s] %s %s — %s ms | user:%s | ip:%s | detail:%s',
                strtoupper($severity),
                $request->method(),
                $request->path(),
                round($durationMs, 2),
                $detail['user']['id'] ?? 'guest',
                $request->ip(),
                json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
        ];
    }

    /**
     * Build syslog-compatible payload for an exception.
     * Used when batching exceptions via POST /logs/batch.
     */
    public static function fromException(
        Throwable $e,
        ?Request $request = null,
        array $config = []
    ): array {
        $meta = $config['metadata'] ?? [];

        $detail = [
            'exception' => [
                'class'    => get_class($e),
                'message'  => $e->getMessage(),
                'code'     => $e->getCode(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
                'trace'    => self::formatTrace($e),
                'previous' => $e->getPrevious() ? [
                    'class'   => get_class($e->getPrevious()),
                    'message' => $e->getPrevious()->getMessage(),
                ] : null,
            ],
            'request' => $request ? [
                'method' => $request->method(),
                'url'    => $request->fullUrl(),
                'ip'     => $request->ip(),
                'body'   => self::sanitizeBody($request->except([]), $config),
            ] : null,
            'user' => $request ? self::resolveUser($request, $config) : null,
        ];

        return [
            'timestamp' => now()->toIso8601String(),
            'host'      => gethostname(),
            'service'   => $meta['service'] ?? config('app.name', 'laravel'),
            'severity'  => 'error',
            'message'   => sprintf(
                '[EXCEPTION] %s: %s in %s:%d | detail:%s',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
        ];
    }

    /**
     * Build syslog-compatible payload for a custom event.
     */
    public static function fromCustomEvent(
        string $event,
        array $data,
        string $level,
        array $config = []
    ): array {
        $meta     = $config['metadata'] ?? [];
        $severity = self::normalizeSeverity($level);

        return [
            'timestamp' => now()->toIso8601String(),
            'host'      => gethostname(),
            'service'   => $meta['service'] ?? config('app.name', 'laravel'),
            'severity'  => $severity,
            'message'   => sprintf(
                '[EVENT] %s | detail:%s',
                $event,
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
        ];
    }

    /**
     * Build syslog-compatible payload for a slow query.
     */
    public static function fromSlowQuery(
        string $sql,
        array $bindings,
        float $time,
        array $config = []
    ): array {
        $meta = $config['metadata'] ?? [];

        return [
            'timestamp' => now()->toIso8601String(),
            'host'      => gethostname(),
            'service'   => $meta['service'] ?? config('app.name', 'laravel'),
            'severity'  => 'warning',
            'message'   => sprintf(
                '[SLOW_QUERY] %.2f ms | sql:%s | bindings:%s',
                $time,
                $sql,
                json_encode($bindings, JSON_UNESCAPED_UNICODE)
            ),
        ];
    }

    // -------------------------------------------------------------------------
    // Severity helpers
    // -------------------------------------------------------------------------

    /**
     * Map HTTP status code to syslog severity.
     * 2xx → info, 4xx → warning, 5xx → error
     */
    protected static function severityFromStatus(?int $status): string
    {
        if ($status === null)                    return 'info';
        if ($status < 400)                       return 'info';
        if ($status >= 400 && $status < 500)     return 'warning';
        if ($status >= 500)                      return 'error';
        return 'notice';
    }

    /**
     * Normalize arbitrary level strings to valid syslog severity values.
     * Allowed: emergency | alert | critical | error | warning | notice | info | debug
     */
    protected static function normalizeSeverity(string $level): string
    {
        $map = [
            'emergency' => 'emergency',
            'alert'     => 'alert',
            'critical'  => 'critical',
            'error'     => 'error',
            'err'       => 'error',
            'warning'   => 'warning',
            'warn'      => 'warning',
            'notice'    => 'notice',
            'info'      => 'info',
            'debug'     => 'debug',
        ];

        return $map[strtolower($level)] ?? 'info';
    }

    // -------------------------------------------------------------------------
    // Sanitizers
    // -------------------------------------------------------------------------

    protected static function sanitizeHeaders(array $headers, array $config): array
    {
        $sensitive = array_map('strtolower', $config['sensitive_headers'] ?? []);

        // Always redact the token_header configured by the user (e.g. X-API-Key)
        // so it never leaks into the logged payload regardless of what name they chose.
        if (! empty($config['token_header'])) {
            $sensitive[] = strtolower($config['token_header']);
        }

        $sensitive = array_unique($sensitive);

        return collect($headers)
            ->mapWithKeys(function ($value, $key) use ($sensitive) {
                $val = is_array($value) ? implode(', ', $value) : $value;
                return [
                    $key => in_array(strtolower($key), $sensitive, true)
                        ? '***REDACTED***'
                        : $val,
                ];
            })
            ->toArray();
    }

    protected static function sanitizeBody(array $body, array $config): array
    {
        $sensitive = array_map('strtolower', $config['sensitive_fields'] ?? []);
        return self::recursiveRedact($body, $sensitive);
    }

    protected static function recursiveRedact(array $data, array $sensitiveKeys): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = self::recursiveRedact($value, $sensitiveKeys);
            }
        }
        return $data;
    }

    // -------------------------------------------------------------------------
    // Extractors
    // -------------------------------------------------------------------------

    protected static function extractResponse($response, array $config): array
    {
        if (! $response instanceof Response) {
            return [];
        }

        $body = null;
        if ($config['log']['responses'] ?? false) {
            $content = $response->getContent();
            $maxSize = $config['max_body_size'] ?? 10240;
            if ($maxSize > 0 && strlen($content) > $maxSize) {
                $content = substr($content, 0, $maxSize) . '...[TRUNCATED]';
            }
            $body = $content;
        }

        return [
            'status_code' => $response->getStatusCode(),
            'headers'     => self::sanitizeHeaders($response->headers->all(), $config),
            'body'        => $body,
        ];
    }

    protected static function resolveUser(Request $request, array $config): ?array
    {
        if ($resolver = ($config['user_resolver'] ?? null)) {
            if (class_exists($resolver)) {
                return (new $resolver)->resolve($request);
            }
        }

        if (! $request->user()) {
            return null;
        }

        $user = $request->user();

        return [
            'id'    => $user->getAuthIdentifier(),
            'email' => $user->email ?? null,
            'name'  => $user->name ?? null,
        ];
    }

    protected static function extractSession(Request $request, array $config): ?array
    {
        if (! ($config['include_session'] ?? false)) {
            return [
                'id' => $request->hasSession() ? $request->session()->getId() : null,
            ];
        }

        if (! $request->hasSession()) {
            return null;
        }

        $session  = $request->session();
        $excluded = $config['exclude_session_keys'] ?? [];

        $data = collect($session->all())
            ->filter(function ($value, $key) use ($excluded) {
                foreach ($excluded as $pattern) {
                    if (Str::is($pattern, $key)) return false;
                }
                return true;
            })
            ->toArray();

        return [
            'id'   => $session->getId(),
            'data' => $data,
        ];
    }

    protected static function extractFiles(Request $request): array
    {
        $files = [];
        foreach ($request->allFiles() as $key => $file) {
            if (is_array($file)) {
                $files[$key] = array_map(fn($f) => [
                    'original_name' => $f->getClientOriginalName(),
                    'mime_type'     => $f->getMimeType(),
                    'size'          => $f->getSize(),
                ], $file);
            } else {
                $files[$key] = [
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $file->getMimeType(),
                    'size'          => $file->getSize(),
                ];
            }
        }
        return $files;
    }

    protected static function formatTrace(Throwable $e): array
    {
        return collect($e->getTrace())
            ->take(20)
            ->map(fn($frame) => [
                'file'     => $frame['file'] ?? null,
                'line'     => $frame['line'] ?? null,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ])
            ->toArray();
    }
}
