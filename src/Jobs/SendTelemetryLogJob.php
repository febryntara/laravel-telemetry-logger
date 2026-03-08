<?php

namespace Febryntara\TelemetryLogger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class SendTelemetryLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $backoff;

    public function __construct(
        protected array $payload,
        protected array $config
    ) {
        $this->tries   = $config['queue']['tries'] ?? 3;
        $this->backoff = $config['queue']['backoff'] ?? 10;
    }

    public function handle(): void
    {
        $base = rtrim($this->config['endpoint'] ?? '', '/');

        if (empty($base)) {
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

        $mode = strtolower($this->config['send_mode'] ?? 'single');

        if ($mode === 'adaptive') {
            $this->sendAdaptive($base, $headers);
        } else {
            $this->sendSingle($base . '/logs', $headers, $this->payload);
        }
    }

    /**
     * Single mode: always POST /logs with one payload.
     */
    protected function sendSingle(string $endpoint, array $headers, array $body): void
    {
        $response = Http::withHeaders($headers)
            ->timeout($this->config['timeout'] ?? 5)
            ->post($endpoint, $body);

        if (! $response->successful()) {
            $this->handleFailedResponse($response, $endpoint);
        }
    }

    /**
     * Adaptive mode: check queue depth.
     * - If queue is below threshold → POST /logs (single)
     * - If queue is backed up       → POST /logs/batch (drain in bulk)
     */
    protected function sendAdaptive(string $base, array $headers): void
    {
        $queueName  = $this->config['queue']['name'] ?? 'telemetry';
        $threshold  = $this->config['adaptive']['batch_threshold'] ?? 10;
        $batchSize  = $this->config['adaptive']['batch_size'] ?? 50;

        $queueDepth = $this->getQueueDepth($queueName);

        if ($queueDepth >= $threshold) {
            // Queue is backed up — collect pending payloads and send as batch
            $this->sendBatch($base . '/logs/batch', $headers, $queueName, $batchSize);
        } else {
            // Queue is healthy — send single
            $this->sendSingle($base . '/logs', $headers, $this->payload);
        }
    }

    /**
     * Drain up to $batchSize jobs from cache buffer and POST /logs/batch.
     * Uses a simple Redis/Cache-backed buffer to accumulate payloads.
     */
    protected function sendBatch(string $endpoint, array $headers, string $queueName, int $batchSize): void
    {
        $bufferKey = 'telemetry_batch_buffer';

        // Push current payload into buffer
        $buffer   = \Illuminate\Support\Facades\Cache::get($bufferKey, []);
        $buffer[] = $this->payload;

        if (count($buffer) < $batchSize) {
            // Not enough accumulated yet — save back and wait
            \Illuminate\Support\Facades\Cache::put($bufferKey, $buffer, now()->addMinutes(5));
            return;
        }

        // Flush buffer
        \Illuminate\Support\Facades\Cache::forget($bufferKey);

        $response = Http::withHeaders($headers)
            ->timeout($this->config['timeout'] ?? 5)
            ->post($endpoint, ['logs' => $buffer]);

        if (! $response->successful()) {
            // On failure, push payloads back to buffer so they're not lost
            \Illuminate\Support\Facades\Cache::put($bufferKey, $buffer, now()->addMinutes(10));
            $this->handleFailedResponse($response, $endpoint);
        }
    }

    /**
     * Get approximate queue depth.
     * Works with database and Redis queue drivers.
     */
    protected function getQueueDepth(string $queueName): int
    {
        try {
            $connection = $this->config['queue']['connection']
                ?? config('queue.default');

            $driver = config("queue.connections.{$connection}.driver");

            if ($driver === 'redis') {
                $redis = app('redis')->connection(
                    config("queue.connections.{$connection}.connection", 'default')
                );
                return (int) $redis->llen("queues:{$queueName}");
            }

            if ($driver === 'database') {
                return (int) \Illuminate\Support\Facades\DB::table('jobs')
                    ->where('queue', $queueName)
                    ->count();
            }
        } catch (\Throwable) {
            // If we can't determine depth, assume healthy
        }

        return 0;
    }

    protected function handleFailedResponse($response, string $endpoint): void
    {
        Log::warning('[TelemetryLogger] Microservice returned non-2xx response', [
            'status'   => $response->status(),
            'endpoint' => $endpoint,
        ]);

        if ($response->serverError()) {
            $this->release($this->backoff);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[TelemetryLogger] Failed to send log to microservice after all retries', [
            'error'    => $e->getMessage(),
            'endpoint' => $this->config['endpoint'] ?? null,
        ]);
    }
}
