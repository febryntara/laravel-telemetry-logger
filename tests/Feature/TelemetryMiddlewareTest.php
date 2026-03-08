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
        $app['config']->set('telemetry-logger.endpoint', 'https://logs.example.com/api');
        $app['config']->set('telemetry-logger.send_mode', 'adaptive'); // use queue in tests
        $app['config']->set('telemetry-logger.sensitive_fields', [
            'password', 'token', 'api_key',
        ]);
        $app['config']->set('telemetry-logger.sensitive_headers', [
            'authorization', 'cookie',
        ]);
        $app['config']->set('telemetry-logger.metadata', [
            'service' => 'test-app',
            'env'     => 'testing',
        ]);
        $app['config']->set('telemetry-logger.queue', [
            'connection' => null,
            'name'       => 'telemetry',
            'tries'      => 3,
            'backoff'    => 10,
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/test-route', fn() => response()->json(['status' => 'ok']));
        $router->post('/test-post', fn() => response()->json(['status' => 'ok']));
        $router->get('/telescope/requests', fn() => response()->json([]));
    }

    // -------------------------------------------------------------------------
    // Payload structure
    // -------------------------------------------------------------------------

    public function test_payload_has_syslog_compatible_fields(): void
    {
        Queue::fake();

        $this->get('/test-route');

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            return array_key_exists('timestamp', $payload)
                && array_key_exists('host', $payload)
                && array_key_exists('service', $payload)
                && array_key_exists('severity', $payload)
                && array_key_exists('message', $payload);
        });
    }

    public function test_service_name_comes_from_metadata_config(): void
    {
        Queue::fake();

        $this->get('/test-route');

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);
            return $payload['service'] === 'test-app';
        });
    }

    // -------------------------------------------------------------------------
    // GET & POST requests are logged
    // -------------------------------------------------------------------------

    public function test_get_request_is_logged(): void
    {
        Queue::fake();

        $this->get('/test-route');

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);
            return str_contains($payload['message'], 'GET')
                && str_contains($payload['message'], 'test-route');
        });
    }

    public function test_post_request_is_logged(): void
    {
        Queue::fake();

        $this->postJson('/test-post', ['name' => 'John']);

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);
            return str_contains($payload['message'], 'POST')
                && str_contains($payload['message'], 'test-post');
        });
    }

    // -------------------------------------------------------------------------
    // Severity mapping from HTTP status
    // -------------------------------------------------------------------------

    public function test_2xx_response_has_info_severity(): void
    {
        Queue::fake();

        $this->get('/test-route'); // returns 200

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            return $this->getJobPayload($job)['severity'] === 'info';
        });
    }

    public function test_4xx_response_has_warning_severity(): void
    {
        Queue::fake();

        // Define a route that returns 404
        $this->app['router']->get('/not-found-route', fn() => response()->json([], 404));

        $this->get('/not-found-route');

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            return $this->getJobPayload($job)['severity'] === 'warning';
        });
    }

    public function test_5xx_response_has_error_severity(): void
    {
        Queue::fake();

        $this->app['router']->get('/error-route', fn() => response()->json([], 500));

        $this->get('/error-route');

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            return $this->getJobPayload($job)['severity'] === 'error';
        });
    }

    // -------------------------------------------------------------------------
    // Sensitive data sanitization
    // -------------------------------------------------------------------------

    public function test_sensitive_body_fields_are_redacted_in_message(): void
    {
        Queue::fake();

        $this->postJson('/test-post', [
            'name'     => 'John',
            'password' => 'secret123',
        ]);

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            $message = $this->getJobPayload($job)['message'];

            // Password must be redacted, name must be visible
            return str_contains($message, '***REDACTED***')
                && str_contains($message, 'John')
                && ! str_contains($message, 'secret123');
        });
    }

    public function test_sensitive_headers_are_redacted_in_message(): void
    {
        Queue::fake();

        $this->withHeaders(['Authorization' => 'Bearer super-secret-token'])
            ->get('/test-route');

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            $message = $this->getJobPayload($job)['message'];

            return str_contains($message, '***REDACTED***')
                && ! str_contains($message, 'super-secret-token');
        });
    }

    public function test_nested_sensitive_fields_are_redacted(): void
    {
        Queue::fake();

        $this->postJson('/test-post', [
            'user' => [
                'name'    => 'Jane',
                'api_key' => 'sk-nested-secret',
            ],
        ]);

        Queue::assertPushed(SendTelemetryLogJob::class, function ($job) {
            $message = $this->getJobPayload($job)['message'];

            return str_contains($message, 'Jane')
                && ! str_contains($message, 'sk-nested-secret');
        });
    }

    // -------------------------------------------------------------------------
    // Route exclusion
    // -------------------------------------------------------------------------

    public function test_excluded_routes_are_not_logged(): void
    {
        Queue::fake();

        $this->get('/telescope/requests');

        Queue::assertNotPushed(SendTelemetryLogJob::class);
    }

    // -------------------------------------------------------------------------
    // Disabled logger
    // -------------------------------------------------------------------------

    public function test_logger_disabled_does_not_queue(): void
    {
        Queue::fake();

        config(['telemetry-logger.enabled' => false]);

        $this->get('/test-route');

        Queue::assertNotPushed(SendTelemetryLogJob::class);
    }

    // -------------------------------------------------------------------------
    // Send mode config is passed to job
    // -------------------------------------------------------------------------

    public function test_single_mode_does_not_use_queue(): void
    {
        Queue::fake();

        config(['telemetry-logger.send_mode' => 'single']);

        $this->get('/test-route');

        // single mode uses dispatch_sync — job runs immediately, not via queue
        Queue::assertNotPushed(SendTelemetryLogJob::class);
    }

    public function test_adaptive_mode_uses_queue(): void
    {
        Queue::fake();

        config(['telemetry-logger.send_mode' => 'adaptive']);

        $this->get('/test-route');

        // adaptive mode dispatches to queue
        Queue::assertPushed(SendTelemetryLogJob::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function getJobPayload(SendTelemetryLogJob $job): array
    {
        $ref = new \ReflectionProperty($job, 'payload');
        $ref->setAccessible(true);
        return $ref->getValue($job);
    }

    protected function getJobConfig(SendTelemetryLogJob $job): array
    {
        $ref = new \ReflectionProperty($job, 'config');
        $ref->setAccessible(true);
        return $ref->getValue($job);
    }
}
