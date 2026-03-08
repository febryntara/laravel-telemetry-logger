<?php

namespace Febryntara\TelemetryLogger\Tests\Feature;

use Febryntara\TelemetryLogger\Jobs\SendTelemetryLogJob;
use Febryntara\TelemetryLogger\TelemetryLoggerServiceProvider;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

class TelemetryMiddlewareTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TelemetryLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('telemetry-logger.enabled', true);
        $app['config']->set('telemetry-logger.endpoint', 'https://logs.example.com/api/logs');
        $app['config']->set('telemetry-logger.queue.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/test-route', fn() => response()->json(['status' => 'ok']));
        $router->post('/test-post', fn() => response()->json(['status' => 'ok']));
    }

    public function test_get_request_is_logged(): void
    {
        Queue::fake();

        $this->get('/test-route');

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);
            return $payload['type'] === 'request'
                && $payload['request']['method'] === 'GET';
        });
    }

    public function test_post_request_is_logged(): void
    {
        Queue::fake();

        $this->postJson('/test-post', [
            'name'     => 'John',
            'password' => 'secret123',
        ]);

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);
            return $payload['request']['body']['password'] === '***REDACTED***'
                && $payload['request']['body']['name'] === 'John';
        });
    }

    public function test_excluded_routes_are_not_logged(): void
    {
        Queue::fake();

        // telescope is in exclude_routes by default
        $this->get('/telescope/requests');

        Queue::assertNotPushed(SendTelemetryLogJob::class);
    }

    public function test_sensitive_headers_are_redacted(): void
    {
        Queue::fake();

        $this->withHeaders(['Authorization' => 'Bearer super-secret-token'])
            ->get('/test-route');

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);
            $headers = $payload['request']['headers'];
            $auth    = $headers['authorization'] ?? $headers['Authorization'] ?? null;
            return $auth === '***REDACTED***';
        });
    }

    public function test_logger_disabled_does_not_queue(): void
    {
        config(['telemetry-logger.enabled' => false]);
        Queue::fake();

        $this->get('/test-route');

        Queue::assertNotPushed(SendTelemetryLogJob::class);
    }

    // Helper to extract payload from job via reflection
    protected function getJobPayload(SendTelemetryLogJob $job): array
    {
        $ref = new \ReflectionProperty($job, 'payload');
        $ref->setAccessible(true);
        return $ref->getValue($job);
    }
}
