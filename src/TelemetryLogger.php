<?php

namespace Febryntara\TelemetryLogger;

use Febryntara\TelemetryLogger\Jobs\SendTelemetryLogJob;
use Febryntara\TelemetryLogger\Support\PayloadBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class TelemetryLogger
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Log an HTTP request (and optionally its response).
     */
    public function logRequest(Request $request, $response = null, float $durationMs = 0): void
    {
        if (! $this->shouldLogRequest($request)) {
            return;
        }

        $payload = PayloadBuilder::fromRequest($request, $response, $durationMs, $this->config);

        $this->dispatch($payload);
    }

    /**
     * Log an exception/error.
     */
    public function logException(Throwable $e, ?Request $request = null): void
    {
        $payload = PayloadBuilder::fromException($e, $request, $this->config);

        $this->dispatch($payload);
    }

    /**
     * Log a custom event manually.
     */
    public function logEvent(string $event, array $data = [], string $level = 'info'): void
    {
        $payload = PayloadBuilder::fromCustomEvent($event, $data, $level, $this->config);

        $this->dispatch($payload);
    }

    /**
     * Log a slow query.
     */
    public function logSlowQuery(string $sql, array $bindings, float $time): void
    {
        $payload = PayloadBuilder::fromSlowQuery($sql, $bindings, $time, $this->config);

        $this->dispatch($payload);
    }

    /**
     * Dispatch the payload — either via queue or synchronously.
     */
    protected function dispatch(array $payload): void
    {
        if (empty($this->config['endpoint'])) {
            return;
        }

        $job = new SendTelemetryLogJob($payload, $this->config);

        if ($this->config['queue']['enabled']) {
            dispatch($job)
                ->onConnection($this->config['queue']['connection'])
                ->onQueue($this->config['queue']['name']);
        } else {
            dispatch_sync($job);
        }
    }

    /**
     * Decide whether this request should be logged.
     */
    protected function shouldLogRequest(Request $request): bool
    {
        foreach ($this->config['exclude_routes'] as $pattern) {
            if ($request->is($pattern)) {
                return false;
            }
        }

        return true;
    }
}
