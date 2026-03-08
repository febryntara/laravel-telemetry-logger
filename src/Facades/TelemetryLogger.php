<?php

namespace Febryntara\TelemetryLogger\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void logRequest(\Illuminate\Http\Request $request, $response = null, float $durationMs = 0)
 * @method static void logException(\Throwable $e, ?\Illuminate\Http\Request $request = null)
 * @method static void logEvent(string $event, array $data = [], string $level = 'info')
 * @method static void logSlowQuery(string $sql, array $bindings, float $time)
 *
 * @see \Febryntara\TelemetryLogger\TelemetryLogger
 */
class TelemetryLogger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'telemetry-logger';
    }
}
