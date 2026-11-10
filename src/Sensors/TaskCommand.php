<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Redactor;
use Illuminate\Console\Scheduling\Event;

class TaskCommand
{
    public function __construct(private Redactor $redactor) {}

    public function name(Event $task): string
    {
        preg_match_all('~(?:\x27[^\x27]*\x27|"[^"]*"|[^\s])+~', $task->command ?? '', $matches);
        $tokens = array_map(fn ($token) => trim($token, "\x27\x22"), array_slice($matches[0], 0, 3));
        $executable = basename(str_replace('\\', '/', $tokens[0] ?? ''));
        if (preg_match('/^php(?:\d+(?:\.\d+)*)?(?:\.exe)?$/i', $executable)
            && basename(str_replace('\\', '/', $tokens[1] ?? '')) === 'artisan') {
            return preg_match('/^[a-z0-9:_-]+$/i', $tokens[2] ?? '') ? $tokens[2] : 'artisan';
        }

        return $this->redactor->scrubValue($task->description ?: ($executable ?: 'Callback'));
    }
}
