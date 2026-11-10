<?php

namespace DecisionsAi\Laravel\Tests;

use DecisionsAi\Laravel\DecisionsServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DecisionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('decisions.token', 'test-token');
        $app['config']->set('decisions.url', 'http://127.0.0.1');
        $app['config']->set('decisions.enabled', true);
        $app['config']->set('decisions.environment', 'production');
        $app['config']->set('decisions.deploy', 'a1b2c3');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
