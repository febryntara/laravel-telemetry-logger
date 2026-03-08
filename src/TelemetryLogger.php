<?php

namespace Febryntara\TelemetryLogger;

use Febryntara\TelemetryLogger\Jobs\SendTelemetryLogJob;
use Febryntara\TelemetryLogger\Support\PayloadBuilder;
use Illuminate\Http\Request;
use Throwable;

class TelemetryLogger
{
    public function __construct()
    {
        // Config is read live via config() on every call,
        // so runtime changes (e.g. in tests) are always respected.
    }

    public function logRequest(Request $request, $response = null, float $durationMs = 0): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if (! $this->shouldLogRequest($request)) {
            return;
        }

        $payload = PayloadBuilder::fromRequest($request, $response, $durationMs, $this->config());

        $this->dispatch($payload);
    }

    public function logException(Throwable $e, ?Request $request = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $payload = PayloadBuilder::fromException($e, $request, $this->config());

        $this->dispatch($payload);
    }

    public function logEvent(string $event, array $data = [], string $level = 'info'): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $payload = PayloadBuilder::fromCustomEvent($event, $data, $level, $this->config());

        $this->dispatch($payload);
    }

    public function logSlowQuery(string $sql, array $bindings, float $time): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $payload = PayloadBuilder::fromSlowQuery($sql, $bindings, $time, $this->config());

        $this->dispatch($payload);
    }

    protected function dispatch(array $payload): void
    {
        $config = $this->config();

        if (empty($config['endpoint'])) {
            return;
        }

        $mode = strtolower($config['send_mode'] ?? 'single');
        $job  = new SendTelemetryLogJob($payload, $config);

        if ($mode === 'adaptive') {
            // Adaptive mode — always use queue so jobs can be batched
            // when queue depth exceeds the configured threshold.
            dispatch($job)
                ->onConnection($config['queue']['connection'])
                ->onQueue($config['queue']['name']);
        } else {
            // Single mode (default) — send immediately and synchronously.
            // No queue involved, log is delivered before the response returns.
            dispatch_sync($job);
        }
    }

    protected function shouldLogRequest(Request $request): bool
    {
        foreach ($this->config('exclude_routes') as $pattern) {
            if ($request->is($pattern)) {
                return false;
            }
        }

        return true;
    }

    protected function isEnabled(): bool
    {
        return (bool) config('telemetry-logger.enabled', true);
    }

    /**
     * Read config live on every call so runtime changes are always picked up.
     */
    protected function config(?string $key = null): mixed
    {
        $full = config('telemetry-logger');

        if ($key === null) {
            return $full;
        }

        return data_get($full, $key);
    }
}
