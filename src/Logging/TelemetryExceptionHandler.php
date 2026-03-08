<?php

namespace Febryntara\TelemetryLogger\Logging;

use Febryntara\TelemetryLogger\TelemetryLogger;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Throwable;

class TelemetryExceptionHandler implements ExceptionHandler
{
    public function __construct(
        protected ExceptionHandler $inner,
        protected $app
    ) {}

    public function report(Throwable $e): void
    {
        // Let the original handler do its thing first
        $this->inner->report($e);

        // Then log to our telemetry system
        try {
            if ($this->shouldReport($e)) {
                /** @var TelemetryLogger $logger */
                $logger  = $this->app->make(TelemetryLogger::class);
                $request = $this->app->bound('request') ? $this->app->make('request') : null;
                $logger->logException($e, $request);
            }
        } catch (Throwable) {
            // Never block the original exception handling
        }
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }
}
