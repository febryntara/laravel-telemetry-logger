<?php

namespace Febryntara\TelemetryLogger\Support;

use Febryntara\TelemetryLogger\TelemetryLogger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SlowQueryWatcher
{
    public static function register(TelemetryLogger $logger, int $thresholdMs): void
    {
        DB::listen(function (QueryExecuted $query) use ($logger, $thresholdMs) {
            if ($query->time >= $thresholdMs) {
                try {
                    $logger->logSlowQuery(
                        $query->sql,
                        $query->bindings,
                        $query->time
                    );
                } catch (\Throwable $e) {
                    Log::warning('[TelemetryLogger] Failed to log slow query: ' . $e->getMessage());
                }
            }
        });
    }
}
