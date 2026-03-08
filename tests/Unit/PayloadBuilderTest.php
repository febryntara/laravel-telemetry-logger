<?php

namespace Febryntara\TelemetryLogger\Tests\Unit;

use Febryntara\TelemetryLogger\Support\PayloadBuilder;
use PHPUnit\Framework\TestCase;

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
            'metadata'          => ['service' => 'test'],
            'user_resolver'     => null,
        ];
    }

    public function test_sensitive_fields_are_redacted(): void
    {
        $body = PayloadBuilder::exposeSanitizeBody([
            'name'     => 'John',
            'password' => 'secret',
            'api_key'  => 'key-123',
        ], $this->baseConfig);

        $this->assertEquals('John', $body['name']);
        $this->assertEquals('***REDACTED***', $body['password']);
        $this->assertEquals('***REDACTED***', $body['api_key']);
    }

    public function test_nested_sensitive_fields_are_redacted(): void
    {
        $body = PayloadBuilder::exposeSanitizeBody([
            'user' => [
                'name'     => 'Jane',
                'password' => 'nested-secret',
            ],
        ], $this->baseConfig);

        $this->assertEquals('Jane', $body['user']['name']);
        $this->assertEquals('***REDACTED***', $body['user']['password']);
    }

    public function test_sensitive_headers_are_redacted(): void
    {
        $headers = PayloadBuilder::exposeSanitizeHeaders([
            'authorization' => ['Bearer token123'],
            'content-type'  => ['application/json'],
        ], $this->baseConfig);

        $this->assertEquals('***REDACTED***', $headers['authorization']);
        $this->assertEquals('application/json', $headers['content-type']);
    }
}
