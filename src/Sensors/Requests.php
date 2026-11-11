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
            if ($event->request->attributes->get('decisions.blocked', false)) {
                return;
            }
            $this->lifecycle->handled($event->request, function () use ($event) {
                $request = $event->request;
                $route = $request->route();
                $security = [];
                if (config('decisions.security.capture', false)) {
                    $sensor = app(Security::class);
                    $sensor->requestHandled($event);
                    $security = $sensor->context($request);
                }

                return [
                    't' => 'request',
                    'method' => $request->method(),
                    'route' => $route ? '/'.ltrim($route->uri(), '/') : null,
                    'path' => $route ? '/'.ltrim($route->uri(), '/') : $this->redactor->redactUrl($request->getPathInfo()),
                    'status' => $event->response->getStatusCode(),
                    ...$security,
                    ...$this->users->identity(true, email: false),
                    // Redactor masks credential fields and caps the size; files are never sent.
                    ...(config('decisions.capture.payloads', false) ? ['payload' => $request->except(array_keys($request->allFiles()))] : []),
                ];
            });
        } catch (Throwable) {
        }
    }
}
