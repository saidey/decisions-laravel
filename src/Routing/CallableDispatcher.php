<?php

namespace DecisionsAi\Laravel\Routing;

use DecisionsAi\Laravel\RequestLifecycle;
use Illuminate\Routing\Contracts\CallableDispatcher as Dispatcher;
use Illuminate\Routing\Route;

class CallableDispatcher implements Dispatcher
{
    public function __construct(private Dispatcher $dispatcher, private RequestLifecycle $lifecycle) {}

    public function dispatch(Route $route, $callable)
    {
        $this->lifecycle->actionStarted();
        $returned = false;
        try {
            $response = $this->dispatcher->dispatch($route, $callable);
            $returned = true;

            return $response;
        } finally {
            $this->lifecycle->actionFinished($returned);
        }
    }
}
