<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Redactor;
use DecisionsAi\Laravel\RequestLifecycle;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Throwable;

class OutgoingRequests
{
    public function __construct(private Recorder $recorder, private Redactor $redactor, private RequestLifecycle $lifecycle) {}

    public function start(RequestSending $event): void
    {
        try {
            $this->lifecycle->remember($event->request->toPsrRequest());
        } catch (Throwable) {
        }
    }

    public function handle(ResponseReceived|ConnectionFailed $event): void
    {
        try {
            $url = $event->request->url();
            $ingest = (string) config('decisions.url', 'https://ingest.decisions.site');
            if ($ingest !== '' && str_starts_with($url, $ingest)) {
                return;
            }
            $ms = $event instanceof ResponseReceived ? (float) ($event->response->handlerStats()['total_time'] ?? 0) * 1000 : 0.0;
            $this->recorder->record([
                't' => 'http',
                'ms' => $ms,
                ...$this->lifecycle->take($event->request->toPsrRequest(), $ms),
                'method' => $event->request->method(),
                'host' => parse_url($url, PHP_URL_HOST),
                'url' => $this->redactor->redactUrl($url, allValues: true),
                'status' => $event instanceof ResponseReceived ? $event->response->status() : 0,
            ]);
        } catch (Throwable) {
        }
    }
}
