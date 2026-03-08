<?php

namespace Febryntara\TelemetryLogger\Listeners;

use Febryntara\TelemetryLogger\TelemetryLogger;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;

class LogJobListener
{
    public function __construct(protected TelemetryLogger $logger) {}

    public function handle(JobProcessed|JobFailed $event): void
    {
        // Skip our own jobs to avoid infinite loop
        if (str_contains($event->job->resolveName(), 'SendTelemetryLogJob')) {
            return;
        }

        $failed = $event instanceof JobFailed;

        $this->logger->logEvent('queue.job.' . ($failed ? 'failed' : 'processed'), [
            'job'        => $event->job->resolveName(),
            'queue'      => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'attempts'   => $event->job->attempts(),
            'failed'     => $failed,
            'exception'  => $failed ? $event->exception->getMessage() : null,
        ], $failed ? 'error' : 'info');
    }
}
