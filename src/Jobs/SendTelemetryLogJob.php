<?php

namespace Febryntara\TelemetryLogger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelemetryLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $backoff;

    public function __construct(
        protected array $payload,
        protected array $config
    ) {
        $this->tries  = $config['queue']['tries'] ?? 3;
        $this->backoff = $config['queue']['backoff'] ?? 10;
    }

    public function handle(): void
    {
        $endpoint = $this->config['endpoint'] ?? null;

        if (empty($endpoint)) {
            return;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'X-Source'     => 'laravel-telemetry-logger',
        ];

        if ($token = ($this->config['token'] ?? null)) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = Http::withHeaders($headers)
            ->timeout($this->config['timeout'] ?? 5)
            ->post($endpoint, $this->payload);

        if (! $response->successful()) {
            Log::warning('[TelemetryLogger] Microservice returned non-2xx response', [
                'status'   => $response->status(),
                'endpoint' => $endpoint,
                'type'     => $this->payload['type'] ?? 'unknown',
            ]);

            // Re-queue for retry if server error
            if ($response->serverError()) {
                $this->release($this->backoff);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[TelemetryLogger] Failed to send log to microservice after all retries', [
            'error'    => $e->getMessage(),
            'type'     => $this->payload['type'] ?? 'unknown',
            'endpoint' => $this->config['endpoint'] ?? null,
        ]);
    }
}
