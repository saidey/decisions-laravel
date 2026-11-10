<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Throwable;
use WeakMap;

class Jobs
{
    private ?WeakMap $started = null;

    private ?WeakMap $recorded = null;

    public function __construct(private Recorder $recorder, private Exceptions $exceptions) {}

    public function start(JobQueued|JobProcessing $event): void
    {
        try {
            if (is_object($event->job)) {
                $this->started ??= new WeakMap;
                $this->started[$event->job] = microtime(true);
                $this->recorded ??= new WeakMap;
                unset($this->recorded[$event->job]);
            }
        } catch (Throwable) {
        }
    }

    public function handle(JobProcessed|JobFailed|JobReleasedAfterException $event): void
    {
        try {
            $this->recorded ??= new WeakMap;
            if (isset($this->recorded[$event->job])) {
                return;
            }
            $this->started ??= new WeakMap;
            $started = $this->started[$event->job] ?? microtime(true);
            unset($this->started[$event->job]);
            $status = match (true) {
                $event instanceof JobFailed, $event->job->hasFailed() => 'failed',
                $event instanceof JobReleasedAfterException => 'released',
                $event->job->isDeletedOrReleased() && $event->job->isReleased() => 'released',
                default => 'processed',
            };
            $this->recorded[$event->job] = true;
            $this->recorder->record([
                't' => 'job',
                'ms' => max(0, (microtime(true) - $started) * 1000),
                'class' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempt' => $event->job->attempts(),
                'status' => $status,
            ]);
            if ($event instanceof JobFailed) {
                $this->exceptions->handle($event->exception);
            }
        } catch (Throwable) {
        }
    }
}
