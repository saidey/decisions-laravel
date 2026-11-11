<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Redactor;
use DecisionsAi\Laravel\RequestLifecycle;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Throwable;

class Requests
{
    public function __construct(private Users $users, private Redactor $redactor, private RequestLifecycle $lifecycle) {}

    public function handle(RequestHandled $event): void
    {
        try {
            $this->lifecycle->handled($event->request, function () use ($event) {
                $request = $event->request;
                $route = $request->route();

                return [
                    't' => 'request',
                    'method' => $request->method(),
                    'route' => $route ? '/'.ltrim($route->uri(), '/') : null,
                    'path' => $route ? '/'.ltrim($route->uri(), '/') : $this->redactor->redactUrl($request->getPathInfo()),
                    'status' => $event->response->getStatusCode(),
                    ...$this->users->identity(true, email: false),
                ];
            });
        } catch (Throwable) {
        }
    }
}
