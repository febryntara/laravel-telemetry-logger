<?php

namespace Febryntara\TelemetryLogger;

use Febryntara\TelemetryLogger\Http\Middleware\TelemetryLoggerMiddleware;
use Febryntara\TelemetryLogger\Listeners\LogExceptionListener;
use Febryntara\TelemetryLogger\Listeners\LogJobListener;
use Febryntara\TelemetryLogger\Listeners\LogCommandListener;
use Febryntara\TelemetryLogger\Support\SlowQueryWatcher;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class TelemetryLoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/telemetry-logger.php',
            'telemetry-logger'
        );

        $this->app->singleton(TelemetryLogger::class, function ($app) {
            return new TelemetryLogger();
        });

        $this->app->alias(TelemetryLogger::class, 'telemetry-logger');
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/telemetry-logger.php' => config_path('telemetry-logger.php'),
        ], 'telemetry-logger-config');

        if (! config('telemetry-logger.enabled')) {
            return;
        }

        // Register HTTP middleware globally
        $this->registerMiddleware();

        // Listen for unhandled exceptions
        if (config('telemetry-logger.log.exceptions')) {
            $this->registerExceptionListener();
        }

        // Listen for queue jobs
        if (config('telemetry-logger.log.jobs')) {
            Event::listen(JobProcessed::class, LogJobListener::class);
            Event::listen(JobFailed::class, LogJobListener::class);
        }

        // Listen for artisan commands
        if (config('telemetry-logger.log.commands')) {
            Event::listen(CommandFinished::class, LogCommandListener::class);
        }

        // Watch for slow queries
        if (config('telemetry-logger.log.slow_queries')) {
            $logger    = $this->app->make(TelemetryLogger::class);
            $threshold = (int) config('telemetry-logger.log.slow_query_threshold', 1000);
            SlowQueryWatcher::register($logger, $threshold);
        }
    }

    protected function registerMiddleware(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->pushMiddleware(TelemetryLoggerMiddleware::class);
    }

    protected function registerExceptionListener(): void
    {
        $this->app->make('events')->listen(
            \Illuminate\Foundation\Exceptions\ReportableException::class,
            LogExceptionListener::class
        );

        // Also hook into the exception handler
        $this->app->extend(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            function ($handler, $app) {
                return new \Febryntara\TelemetryLogger\Logging\TelemetryExceptionHandler($handler, $app);
            }
        );
    }
}
