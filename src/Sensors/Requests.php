<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Redactor;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Throwable;

class Requests
{
    public function __construct(private Recorder $recorder, private Users $users, private Redactor $redactor) {}

    public function handle(RequestHandled $event): void
    {
        try {
            $request = $event->request;
            $route = $request->route();
            $started = $request->server('REQUEST_TIME_FLOAT', defined('LARAVEL_START') ? LARAVEL_START : microtime(true));
            $this->recorder->record([
                't' => 'request',
                'ms' => max(0, (microtime(true) - $started) * 1000),
                'method' => $request->method(),
                'route' => $route ? '/'.ltrim($route->uri(), '/') : null,
                'path' => $route ? '/'.ltrim($route->uri(), '/') : $this->redactor->redactUrl($request->getPathInfo()),
                'status' => $event->response->getStatusCode(),
                ...$this->users->identity(),
            ]);
        } catch (Throwable) {
        }
    }
}
