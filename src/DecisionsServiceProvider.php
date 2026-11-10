<?php

namespace DecisionsAi\Laravel;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
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
                    return new Transport($app['config']->get('decisions', []));
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
            $start = function ($event) {
                try {
                    if ($event instanceof JobProcessing) {
                        $this->app->make(Recorder::class)->startJobTrace($event->job, $event->job->resolveName());

                        return;
                    }
                    [$type, $name] = match (true) {
                        $event instanceof CommandStarting => ['command', $event->command],
                        default => ['request', isset($event->request) ? $event->request->getPathInfo() : null],
                    };
                    $this->app->make(Recorder::class)->startTrace($type, $name);
                } catch (Throwable) {
                    // Monitoring must not interrupt a new unit of work.
                }
            };

            foreach ([JobProcessing::class, CommandStarting::class, 'Laravel\\Octane\\Events\\RequestReceived'] as $event) {
                $this->app['events']->listen($event, $start);
            }
            $this->app->rebinding('request', function ($app, $request) {
                try {
                    $app->make(Recorder::class)->startTrace('request', $request->getPathInfo());
                } catch (Throwable) {
                }
            });

            $listeners = [
                Sensors\Requests::class => [RequestHandled::class],
                Sensors\Jobs::class => [JobProcessed::class, JobFailed::class, JobReleasedAfterException::class],
                Sensors\Commands::class => [CommandFinished::class],
                Sensors\ScheduledTasks::class => [ScheduledTaskFinished::class, ScheduledTaskFailed::class, ScheduledBackgroundTaskFinished::class],
                Sensors\Queries::class => [QueryExecuted::class],
                Sensors\Cache::class => [CacheHit::class, CacheMissed::class, KeyWritten::class, KeyForgotten::class],
                Sensors\Mail::class => [MessageSent::class],
                Sensors\Notifications::class => [NotificationSent::class],
                Sensors\OutgoingRequests::class => [ResponseReceived::class, ConnectionFailed::class],
                Sensors\Schedule::class => [CommandStarting::class],
            ];
            foreach ([...array_keys($listeners), Sensors\Exceptions::class, Sensors\Users::class, Sensors\Logs::class] as $sensor) {
                $this->app->singleton($sensor);
            }
            foreach ($this->app['config']->get('logging.channels', []) as $name => $channel) {
                $this->app['config']->set('logging.channels.'.$name.'.tap', [...($channel['tap'] ?? []), Sensors\Logs::class]);
            }
            $this->callAfterResolving('log', function ($log) {
                try {
                    foreach ($log->getChannels() as $logger) {
                        $this->app->make(Sensors\Logs::class)->__invoke($logger);
                    }
                } catch (Throwable) {
                }
            });
            foreach ($listeners as $sensor => $events) {
                foreach ($events as $event) {
                    $this->app['events']->listen($event, function ($event) use ($sensor) {
                        try {
                            $this->app->make($sensor)->handle($event);
                        } catch (Throwable) {
                        }
                    });
                }
            }
            foreach ([JobQueued::class => Sensors\Jobs::class, JobProcessing::class => Sensors\Jobs::class, CommandStarting::class => Sensors\Commands::class] as $event => $sensor) {
                $this->app['events']->listen($event, function ($event) use ($sensor) {
                    try {
                        $this->app->make($sensor)->start($event);
                    } catch (Throwable) {
                    }
                });
            }
            $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
                try {
                    $handler->reportable(function (Throwable $exception) {
                        try {
                            $this->app->make(Sensors\Exceptions::class)->handle($exception);
                        } catch (Throwable) {
                        }
                    });
                } catch (Throwable) {
                }
            });

            $this->app->terminating($flush);
            foreach ([JobProcessed::class, JobFailed::class, JobReleasedAfterException::class] as $event) {
                $this->app['events']->listen($event, function ($event) {
                    try {
                        $this->app->make(Recorder::class)->finishJobTrace($event->job);
                    } catch (Throwable) {
                    }
                });
            }
            foreach ([CommandFinished::class, 'Laravel\\Octane\\Events\\RequestTerminated'] as $event) {
                $this->app['events']->listen($event, $flush);
            }
        } catch (Throwable) {
            // Monitoring must not interrupt application boot.
        }
    }
}
