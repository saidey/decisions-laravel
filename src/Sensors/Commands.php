<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Throwable;

class Commands
{
    private array $started = [];

    public function __construct(private Recorder $recorder) {}

    public function start(CommandStarting $event): void
    {
        try {
            $this->started[$event->command] = microtime(true);
        } catch (Throwable) {
        }
    }

    public function handle(CommandFinished $event): void
    {
        try {
            $started = $this->started[$event->command] ?? microtime(true);
            unset($this->started[$event->command]);
            $this->recorder->record([
                't' => 'command',
                'ms' => max(0, (microtime(true) - $started) * 1000),
                'name' => $event->command,
                'exit' => $event->exitCode,
            ]);
        } catch (Throwable) {
        }
    }
}
