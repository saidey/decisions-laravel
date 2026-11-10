<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Throwable;

class ScheduledTasks
{
    public function __construct(private Recorder $recorder) {}

    public function handle(ScheduledTaskFinished|ScheduledTaskFailed|ScheduledBackgroundTaskFinished $event): void
    {
        try {
            // Laravel emits Failed after Finished for foreground commands with a nonzero exit.
            if ($event instanceof ScheduledTaskFinished && ($event->task->runInBackground || ($event->task->exitCode ?? 0) !== 0)) {
                return;
            }
            $this->recorder->record([
                't' => 'task',
                'ms' => (float) ($event->runtime ?? 0) * 1000,
                'command' => $event->task->command ?? $event->task->getSummaryForDisplay(),
                'cron' => $event->task->expression,
                'status' => $event instanceof ScheduledTaskFailed || ($event->task->exitCode ?? 0) !== 0 ? 'failed' : 'ok',
            ]);
        } catch (Throwable) {
        }
    }
}
