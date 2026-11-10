<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;
use Throwable;

class Schedule
{
    private bool $reporting = false;

    public function __construct(private Recorder $recorder) {}

    public function handle(CommandStarting $event): void
    {
        try {
            if ($this->reporting || ! in_array($event->command, ['schedule:run', 'schedule:work'], true)) {
                return;
            }
            $this->reporting = true;
            $key = 'decisions:schedule-reported:'.config('decisions.deploy');
            $tasks = [];
            foreach (app(LaravelSchedule::class)->events() as $task) {
                $tasks[] = [
                    'command' => $task->command ?? $task->getSummaryForDisplay(),
                    'cron' => $task->expression,
                    'timezone' => $task->timezone instanceof \DateTimeZone ? $task->timezone->getName() : ($task->timezone ?? config('app.timezone', 'UTC')),
                ];
            }
            if (cache()->add($key, true, 3600)) {
                $this->recorder->record(['t' => 'schedule', 'at' => microtime(true), 'tasks' => $tasks]);
            }
        } catch (Throwable) {
        } finally {
            $this->reporting = false;
        }
    }
}
