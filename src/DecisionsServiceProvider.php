<?php

namespace DecisionsAi\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Throwable;

class DecisionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        try {
            $this->mergeConfigFrom(__DIR__.'/../config/decisions.php', 'decisions');

            $this->app->singleton(Transport::class, function ($app) {
                try {
                    return new Transport($app['config']->get('decisions', []), $app->make(Factory::class));
                } catch (Throwable) {
                    return new Transport(['enabled' => false]);
                }
            });
            $this->app->singleton(Redactor::class, function ($app) {
                try {
                    return new Redactor($app['config']->get('decisions', []));
                } catch (Throwable) {
                    return new Redactor(['invalid' => true]);
                }
            });
            $this->app->singleton(Recorder::class, function ($app) {
                try {
                    return new Recorder($app->make(Transport::class), $app->make(Redactor::class), $app['config']->get('decisions', []));
                } catch (Throwable) {
                    return new Recorder(new Transport, new Redactor, ['enabled' => false]);
                }
            });
        } catch (Throwable) {
            // Monitoring must not interrupt application registration.
        }
    }

    public function boot(): void
    {
        try {
            $this->publishes([__DIR__.'/../config/decisions.php' => config_path('decisions.php')], 'decisions-config');

            $flush = function () {
                try {
                    $this->app->make(Recorder::class)->flush();
                } catch (Throwable) {
                    // Monitoring must not interrupt application termination.
                }
            };
            $start = function () {
                try {
                    $this->app->make(Recorder::class)->startTrace();
                } catch (Throwable) {
                    // Monitoring must not interrupt a new unit of work.
                }
            };

            $this->app->terminating($flush);
            foreach ([JobProcessed::class, JobFailed::class, CommandFinished::class, 'Laravel\\Octane\\Events\\RequestTerminated'] as $event) {
                $this->app['events']->listen($event, $flush);
            }
            foreach ([JobProcessing::class, CommandStarting::class, 'Laravel\\Octane\\Events\\RequestReceived'] as $event) {
                $this->app['events']->listen($event, $start);
            }
        } catch (Throwable) {
            // Monitoring must not interrupt application boot.
        }
    }
}
