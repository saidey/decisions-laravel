<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\RequestLifecycle;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Throwable;
use WeakMap;

class Jobs
{
    private ?WeakMap $started = null;

    private ?WeakMap $recorded = null;

    private ?WeakMap $contexts = null;

    public function __construct(private Recorder $recorder, private Exceptions $exceptions, private RequestLifecycle $lifecycle) {}

    public function startDispatch(JobQueueing $event): void
    {
        try {
            $this->lifecycle->remember($this->dispatchKey($event));
        } catch (Throwable) {
        }
    }

    public function recordDispatch(JobQueued $event): void
    {
        try {
            $context = $this->lifecycle->take($this->dispatchKey($event));
            if ($context === []) {
                return;
            }
            $this->recorder->record([
                't' => 'job',
                'ms' => max(0, $this->lifecycle->context()['offset_ms'] - $context['offset_ms']),
                'class' => is_object($event->job) ? get_class($event->job) : ($event->payload()['displayName'] ?? 'unknown'),
                'queue' => $event->queue,
                'attempt' => 0,
                'status' => 'queued',
                ...$context,
            ]);
        } catch (Throwable) {
        }
    }

    private function dispatchKey(JobQueueing|JobQueued $event): string
    {
        return 'job:'.hash('sha256', serialize([$event->connectionName, $event->queue, $event->payload]));
    }

    public function start(JobQueued|JobProcessing $event): void
    {
        try {
            if (is_object($event->job)) {
                $this->started ??= new WeakMap;
                $this->started[$event->job] = microtime(true);
                $this->contexts ??= new WeakMap;
                $this->contexts[$event->job] = [$this->recorder->traceId(), $this->lifecycle->context()];
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
            $this->contexts ??= new WeakMap;
            [$trace, $context] = $this->contexts[$event->job] ?? [null, []];
            unset($this->contexts[$event->job]);
            if ($trace !== $this->recorder->traceId()) {
                $context = [];
            }
            $this->recorder->record([
                't' => 'job',
                ...$context,
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
