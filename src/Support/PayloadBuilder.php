<?php

namespace Febryntara\TelemetryLogger\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PayloadBuilder
{
    public static function fromRequest(
        Request $request,
        $response = null,
        float $durationMs = 0,
        array $config = []
    ): array {
        $payload = [
            'type'       => 'request',
            'id'         => (string) Str::uuid(),
            'timestamp'  => now()->toISOString(),
            'duration_ms' => round($durationMs, 2),

            'request' => [
                'method'      => $request->method(),
                'url'         => $request->fullUrl(),
                'path'        => $request->path(),
                'route_name'  => optional($request->route())->getName(),
                'route_action'=> optional($request->route())->getActionName(),
                'ip'          => $request->ip(),
                'ips'         => $request->ips(),
                'user_agent'  => $request->userAgent(),
                'referer'     => $request->headers->get('referer'),
                'headers'     => self::sanitizeHeaders($request->headers->all(), $config),
                'body'        => self::sanitizeBody($request->except([]), $config),
                'query'       => $request->query->all(),
                'files'       => self::extractFiles($request),
            ],

            'response' => $response ? self::extractResponse($response, $config) : null,

            'user'    => self::resolveUser($request, $config),
            'session' => self::extractSession($request, $config),
            'server'  => self::extractServer(),
            'meta'    => $config['metadata'] ?? [],
        ];

        return $payload;
    }

    public static function fromException(
        Throwable $e,
        ?Request $request = null,
        array $config = []
    ): array {
        return [
            'type'      => 'exception',
            'id'        => (string) Str::uuid(),
            'timestamp' => now()->toISOString(),

            'exception' => [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => self::formatTrace($e),
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

            'user'   => $request ? self::resolveUser($request, $config) : null,
            'server' => self::extractServer(),
            'meta'   => $config['metadata'] ?? [],
        ];
    }

    public static function fromCustomEvent(
        string $event,
        array $data,
        string $level,
        array $config = []
    ): array {
        return [
            'type'      => 'event',
            'id'        => (string) Str::uuid(),
            'timestamp' => now()->toISOString(),
            'level'     => $level,
            'event'     => $event,
            'data'      => $data,
            'server'    => self::extractServer(),
            'meta'      => $config['metadata'] ?? [],
        ];
    }

    public static function fromSlowQuery(
        string $sql,
        array $bindings,
        float $time,
        array $config = []
    ): array {
        return [
            'type'       => 'slow_query',
            'id'         => (string) Str::uuid(),
            'timestamp'  => now()->toISOString(),
            'duration_ms' => round($time, 2),
            'sql'        => $sql,
            'bindings'   => $bindings,
            'server'     => self::extractServer(),
            'meta'       => $config['metadata'] ?? [],
        ];
    }

    // -------------------------------------------------------------------------

    protected static function sanitizeHeaders(array $headers, array $config): array
    {
        $sensitive = array_map('strtolower', $config['sensitive_headers'] ?? []);

        return collect($headers)
            ->mapWithKeys(function ($value, $key) use ($sensitive) {
                $normalized = strtolower($key);
                $val = is_array($value) ? implode(', ', $value) : $value;

                return [
                    $key => in_array($normalized, $sensitive, true)
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
            'headers'     => self::sanitizeHeaders(
                $response->headers->all(),
                $config
            ),
            'body' => $body,
        ];
    }

    protected static function resolveUser(Request $request, array $config): ?array
    {
        // Custom resolver takes priority
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

        $session = $request->session();
        $excluded = $config['exclude_session_keys'] ?? [];

        $data = collect($session->all())
            ->filter(function ($value, $key) use ($excluded) {
                foreach ($excluded as $pattern) {
                    if (Str::is($pattern, $key)) {
                        return false;
                    }
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

    protected static function extractServer(): array
    {
        return [
            'hostname' => gethostname(),
            'php'      => PHP_VERSION,
        ];
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
