<?php

namespace Febryntara\TelemetryLogger\Http\Middleware;

use Febryntara\TelemetryLogger\TelemetryLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TelemetryLoggerMiddleware
{
    public function __construct(protected TelemetryLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (microtime(true) - $start) * 1000;

        // Fire and forget — exceptions inside logger should never bubble up
        try {
            $this->logger->logRequest($request, $response, $durationMs);
        } catch (\Throwable) {
            // Silently ignore logging failures
        }

        return $response;
    }
}
