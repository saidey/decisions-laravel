<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Illuminate\Log\Events\MessageLogged;
use Throwable;

class Logs
{
    public function __construct(private Recorder $recorder) {}

    public function handle(MessageLogged $event): void
    {
        try {
            $this->recorder->record([
                't' => 'log',
                'level' => $event->level,
                'channel' => config('logging.default', 'stack'),
                'message' => (string) $event->message,
                'context' => $event->context === [] ? (object) [] : $event->context,
            ]);
        } catch (Throwable) {
        }
    }
}
