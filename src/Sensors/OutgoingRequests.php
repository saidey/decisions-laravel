<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Throwable;

class OutgoingRequests
{
    public function __construct(private Recorder $recorder) {}

    public function handle(ResponseReceived|ConnectionFailed $event): void
    {
        try {
            $url = $event->request->url();
            $ingest = (string) config('decisions.url', 'https://ingest.decisions.site');
            if ($ingest !== '' && str_starts_with($url, $ingest)) {
                return;
            }
            $this->recorder->record([
                't' => 'http',
                'ms' => $event instanceof ResponseReceived ? (float) ($event->response->handlerStats()['total_time'] ?? 0) * 1000 : 0.0,
                'method' => $event->request->method(),
                'host' => parse_url($url, PHP_URL_HOST),
                'url' => $url,
                'status' => $event instanceof ResponseReceived ? $event->response->status() : 0,
            ]);
        } catch (Throwable) {
        }
    }
}
