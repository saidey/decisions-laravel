<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Illuminate\Mail\Events\MessageSent;
use Throwable;

class Mail
{
    public function __construct(private Recorder $recorder) {}

    public function handle(MessageSent $event): void
    {
        try {
            $this->recorder->record([
                't' => 'mail',
                'ms' => 0.0,
                'class' => $event->data['__laravel_mailable'] ?? get_class($event->message),
                'to_count' => count($event->message->getTo()),
            ]);
        } catch (Throwable) {
        }
    }
}
