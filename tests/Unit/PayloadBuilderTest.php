<?php

namespace Febryntara\TelemetryLogger\Tests\Unit;

use Febryntara\TelemetryLogger\Support\PayloadBuilder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Orchestra\Testbench\TestCase;

class PayloadBuilderTest extends TestCase
{
    private array $baseConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseConfig = [
            'sensitive_fields'  => ['password', 'token', 'api_key'],
            'sensitive_headers' => ['authorization', 'cookie'],
            'max_body_size'     => 10240,
            'include_session'   => false,
            'log'               => ['responses' => false],
            'metadata'          => ['service' => 'test-app', 'env' => 'testing'],
            'user_resolver'     => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Syslog-compatible structure
    // -------------------------------------------------------------------------

    public function test_fromRequest_returns_syslog_fields(): void
    {
        $request  = Request::create('/api/users', 'GET');
        $payload  = PayloadBuilder::fromRequest($request, null, 0, $this->baseConfig);

        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('host',      $payload);
        $this->assertArrayHasKey('service',   $payload);
        $this->assertArrayHasKey('severity',  $payload);
        $this->assertArrayHasKey('message',   $payload);
    }

    public function test_fromRequest_has_no_nested_request_key(): void
    {
        $request = Request::create('/api/users', 'GET');
        $payload = PayloadBuilder::fromRequest($request, null, 0, $this->baseConfig);

        // Old nested format must not exist
        $this->assertArrayNotHasKey('type',    $payload);
        $this->assertArrayNotHasKey('request', $payload);
        $this->assertArrayNotHasKey('user',    $payload);
    }

    public function test_service_name_comes_from_metadata(): void
    {
        $request = Request::create('/api/test', 'GET');
        $payload = PayloadBuilder::fromRequest($request, null, 0, $this->baseConfig);

        $this->assertEquals('test-app', $payload['service']);
    }

    // -------------------------------------------------------------------------
    // Severity mapping
    // -------------------------------------------------------------------------

    public function test_severity_is_info_for_2xx(): void
    {
        $request  = Request::create('/test', 'GET');
        $response = new \Illuminate\Http\JsonResponse([], 200);
        $payload  = PayloadBuilder::fromRequest($request, $response, 0, $this->baseConfig);

        $this->assertEquals('info', $payload['severity']);
    }

    public function test_severity_is_warning_for_4xx(): void
    {
        $request  = Request::create('/test', 'GET');
        $response = new \Illuminate\Http\JsonResponse([], 422);
        $payload  = PayloadBuilder::fromRequest($request, $response, 0, $this->baseConfig);

        $this->assertEquals('warning', $payload['severity']);
    }

    public function test_severity_is_error_for_5xx(): void
    {
        $request  = Request::create('/test', 'GET');
        $response = new \Illuminate\Http\JsonResponse([], 500);
        $payload  = PayloadBuilder::fromRequest($request, $response, 0, $this->baseConfig);

        $this->assertEquals('error', $payload['severity']);
    }

    public function test_fromException_severity_is_always_error(): void
    {
        $e       = new \RuntimeException('Something went wrong');
        $payload = PayloadBuilder::fromException($e, null, $this->baseConfig);

        $this->assertEquals('error', $payload['severity']);
    }

    public function test_fromSlowQuery_severity_is_warning(): void
    {
        $payload = PayloadBuilder::fromSlowQuery(
            'SELECT * FROM users',
            [],
            1500,
            $this->baseConfig
        );

        $this->assertEquals('warning', $payload['severity']);
    }

    // -------------------------------------------------------------------------
    // Message content
    // -------------------------------------------------------------------------

    public function test_message_contains_method_and_path(): void
    {
        $request = Request::create('/api/orders', 'POST');
        $payload = PayloadBuilder::fromRequest($request, null, 55.3, $this->baseConfig);

        $this->assertStringContainsString('POST',       $payload['message']);
        $this->assertStringContainsString('api/orders', $payload['message']);
        $this->assertStringContainsString('55.3',       $payload['message']);
    }

    public function test_exception_message_contains_class_and_message(): void
    {
        $e       = new \InvalidArgumentException('Bad input', 42);
        $payload = PayloadBuilder::fromException($e, null, $this->baseConfig);

        $this->assertStringContainsString('InvalidArgumentException', $payload['message']);
        $this->assertStringContainsString('Bad input',                $payload['message']);
    }

    public function test_slow_query_message_contains_sql_and_time(): void
    {
        $payload = PayloadBuilder::fromSlowQuery(
            'SELECT * FROM orders WHERE status = ?',
            ['pending'],
            2300.5,
            $this->baseConfig
        );

        $this->assertStringContainsString('SELECT * FROM orders', $payload['message']);
        $this->assertStringContainsString('2300.50',              $payload['message']);
    }

    public function test_custom_event_message_contains_event_name(): void
    {
        $payload = PayloadBuilder::fromCustomEvent(
            'payment.completed',
            ['order_id' => 99],
            'info',
            $this->baseConfig
        );

        $this->assertStringContainsString('payment.completed', $payload['message']);
    }

    // -------------------------------------------------------------------------
    // Sensitive field sanitization — body
    // -------------------------------------------------------------------------

    public function test_sensitive_body_fields_are_redacted(): void
    {
        $request = Request::create('/login', 'POST', [
            'email'    => 'user@example.com',
            'password' => 'secret123',
        ]);

        $payload = PayloadBuilder::fromRequest($request, null, 0, $this->baseConfig);

        $this->assertStringNotContainsString('secret123',    $payload['message']);
        $this->assertStringContainsString('***REDACTED***',  $payload['message']);
        $this->assertStringContainsString('user@example.com', $payload['message']);
    }

    public function test_nested_sensitive_fields_are_redacted(): void
    {
        $request = Request::create('/api/keys', 'POST', [
            'user' => [
                'name'    => 'Jane',
                'api_key' => 'sk-super-secret',
            ],
        ]);

        $payload = PayloadBuilder::fromRequest($request, null, 0, $this->baseConfig);

        $this->assertStringNotContainsString('sk-super-secret', $payload['message']);
        $this->assertStringContainsString('Jane',               $payload['message']);
    }

    public function test_field_matching_is_case_insensitive(): void
    {
        $request = Request::create('/test', 'POST', [
            'PASSWORD' => 'should-be-redacted',
            'Token'    => 'also-redacted',
        ]);

        $payload = PayloadBuilder::fromRequest($request, null, 0, $this->baseConfig);

        $this->assertStringNotContainsString('should-be-redacted', $payload['message']);
        $this->assertStringNotContainsString('also-redacted',       $payload['message']);
    }

    // -------------------------------------------------------------------------
    // Sensitive header sanitization
    // -------------------------------------------------------------------------

    public function test_sensitive_headers_are_redacted(): void
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('Authorization', 'Bearer super-secret-token');
        $request->headers->set('Content-Type',  'application/json');

        $payload = PayloadBuilder::fromRequest($request, null, 0, $this->baseConfig);

        $this->assertStringNotContainsString('super-secret-token', $payload['message']);
        $this->assertStringContainsString('application/json',      $payload['message']);
    }

    public function test_token_header_authorization_is_auto_redacted(): void
    {
        // Default token_header = Authorization — should be redacted automatically
        $config  = array_merge($this->baseConfig, ['token_header' => 'Authorization']);
        $request = Request::create('/test', 'GET');
        $request->headers->set('Authorization', 'Bearer my-outbound-token');

        $payload = PayloadBuilder::fromRequest($request, null, 0, $config);

        $this->assertStringNotContainsString('my-outbound-token', $payload['message']);
    }

    public function test_custom_token_header_is_auto_redacted(): void
    {
        // User configured X-API-Key as token_header — must be redacted even if
        // it is not listed in sensitive_headers
        $config  = array_merge($this->baseConfig, [
            'sensitive_headers' => ['authorization', 'cookie'], // X-API-Key NOT listed
            'token_header'      => 'X-API-Key',
        ]);

        $request = Request::create('/test', 'GET');
        $request->headers->set('X-API-Key',    'super-secret-api-key');
        $request->headers->set('Content-Type', 'application/json');

        $payload = PayloadBuilder::fromRequest($request, null, 0, $config);

        $this->assertStringNotContainsString('super-secret-api-key', $payload['message']);
        $this->assertStringContainsString('application/json',        $payload['message']);
    }

    public function test_custom_token_header_matching_is_case_insensitive(): void
    {
        // Header names are case-insensitive per HTTP spec
        $config  = array_merge($this->baseConfig, ['token_header' => 'X-Custom-Auth']);
        $request = Request::create('/test', 'GET');
        $request->headers->set('x-custom-auth', 'secret-value');

        $payload = PayloadBuilder::fromRequest($request, null, 0, $config);

        $this->assertStringNotContainsString('secret-value', $payload['message']);
    }

    public function test_token_header_not_set_does_not_break_sanitization(): void
    {
        // token_header is null/missing — sanitization should still work normally
        $config  = array_merge($this->baseConfig, ['token_header' => null]);
        $request = Request::create('/test', 'GET');
        $request->headers->set('Authorization', 'Bearer some-token');

        $payload = PayloadBuilder::fromRequest($request, null, 0, $config);

        // Authorization is in sensitive_headers so it should still be redacted
        $this->assertStringNotContainsString('some-token', $payload['message']);
    }

    // -------------------------------------------------------------------------
    // Custom event severity normalization
    // -------------------------------------------------------------------------

    /**
     * @dataProvider severityNormalizationProvider
     */
    public function test_custom_event_severity_is_normalized(string $input, string $expected): void
    {
        $payload = PayloadBuilder::fromCustomEvent('test.event', [], $input, $this->baseConfig);
        $this->assertEquals($expected, $payload['severity']);
    }

    public static function severityNormalizationProvider(): array
    {
        return [
            'info'      => ['info',      'info'],
            'warning'   => ['warning',   'warning'],
            'warn alias'=> ['warn',      'warning'],
            'error'     => ['error',     'error'],
            'err alias' => ['err',       'error'],
            'debug'     => ['debug',     'debug'],
            'critical'  => ['critical',  'critical'],
            'unknown'   => ['whatever',  'info'],   // unknown → fallback to info
        ];
    }
}
