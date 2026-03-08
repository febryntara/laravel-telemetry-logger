<?php

namespace Febryntara\TelemetryLogger\Listeners;

use Febryntara\TelemetryLogger\TelemetryLogger;
use Illuminate\Console\Events\CommandFinished;

class LogCommandListener
{
    public function __construct(protected TelemetryLogger $logger) {}

    public function handle(CommandFinished $event): void
    {
        $this->logger->logEvent('command.finished', [
            'command'   => $event->command,
            'exit_code' => $event->exitCode,
            'input'     => (string) $event->input,
        ], $event->exitCode === 0 ? 'info' : 'warning');
    }
}
