<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

class Notifications
{
    public function __construct(private Recorder $recorder) {}

    public function handle(NotificationSent $event): void
    {
        try {
            $this->recorder->record([
                't' => 'notification',
                'ms' => 0.0,
                'class' => get_class($event->notification),
                'channel' => $event->channel,
            ]);
        } catch (Throwable) {
        }
    }
}
