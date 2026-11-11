<?php

namespace DecisionsAi\Laravel\Routing;

use DecisionsAi\Laravel\RequestLifecycle;
use Illuminate\Routing\Contracts\ControllerDispatcher as Dispatcher;
use Illuminate\Routing\Route;

class ControllerDispatcher implements Dispatcher
{
    public function __construct(private Dispatcher $dispatcher, private RequestLifecycle $lifecycle) {}

    public function dispatch(Route $route, $controller, $method)
    {
        $this->lifecycle->actionStarted();
        $returned = false;
        try {
            $response = $this->dispatcher->dispatch($route, $controller, $method);
            $returned = true;

            return $response;
        } finally {
            $this->lifecycle->actionFinished($returned);
        }
    }

    public function getMiddleware($controller, $method)
    {
        return $this->dispatcher->getMiddleware($controller, $method);
    }
}
