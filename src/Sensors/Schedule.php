<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;
use Throwable;

class Schedule
{
    private bool $reporting = false;

    public function __construct(private Recorder $recorder, private TaskCommand $commands) {}

    public function handle(CommandStarting $event): void
    {
        $stamp = null;
        try {
            if ($this->reporting || ! in_array($event->command, ['schedule:run', 'schedule:work'], true)) {
                return;
            }
            $this->reporting = true;
            $path = storage_path('framework/decisions-schedule-'.rawurlencode((string) config('decisions.deploy')));
            if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0777, true)) {
                return;
            }
            $stamp = @fopen($path, 'c+');
            if ($stamp === false || ! @flock($stamp, LOCK_EX | LOCK_NB)) {
                return;
            }
            $stat = @fstat($stamp);
            if ($stat === false || ($stat['size'] > 0 && $stat['mtime'] > time() - 3600)) {
                return;
            }
            $tasks = [];
            foreach (app(LaravelSchedule::class)->events() as $task) {
                $tasks[] = [
                    'command' => $this->commands->name($task),
                    'cron' => $task->expression,
                    'timezone' => $task->timezone instanceof \DateTimeZone ? $task->timezone->getName() : ($task->timezone ?? config('app.timezone', 'UTC')),
                ];
            }
            if (@fwrite($stamp, '1') === false || ! @touch($path)) {
                return;
            }
            $this->recorder->record(['t' => 'schedule', 'at' => microtime(true), 'tasks' => $tasks]);
        } catch (Throwable) {
        } finally {
            if (is_resource($stamp)) {
                @flock($stamp, LOCK_UN);
                @fclose($stamp);
            }
            $this->reporting = false;
        }
    }
}
