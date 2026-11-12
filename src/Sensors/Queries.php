<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Redactor;
use DecisionsAi\Laravel\RequestLifecycle;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

class Queries
{
    public function __construct(private Recorder $recorder, private RequestLifecycle $lifecycle, private Redactor $redactor) {}

    // Inline bindings only after scrubbing them: emails, tokens, phone numbers and password hashes never leave as SQL text.
    private function sql(QueryExecuted $event): string
    {
        $bindings = $this->redactor->queryBindings($event->sql, $event->connection->prepareBindings($event->bindings));

        return $event->connection->query()->getGrammar()->substituteBindingsIntoRawSql($event->sql, $bindings);
    }

    public function handle(QueryExecuted $event): void
    {
        try {
            $file = null;
            $line = null;
            $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50) as $frame) {
                $path = $frame['file'] ?? '';
                if (str_starts_with($path, $base) && ! str_starts_with($path, $base.'vendor'.DIRECTORY_SEPARATOR)
                    && ! str_starts_with($path, dirname(__DIR__).DIRECTORY_SEPARATOR)) {
                    $file = substr($path, strlen($base));
                    $line = $frame['line'] ?? null;
                    break;
                }
            }
            $this->recorder->record([
                't' => 'query',
                'ms' => (float) $event->time,
                'sql' => config('decisions.capture.bindings', false) ? $this->sql($event) : $event->sql,
                'connection' => $event->connectionName,
                'connection_type' => $event->connection->getDriverName(),
                ...$this->lifecycle->context((float) $event->time),
                'file' => $file,
                'line' => $line,
            ]);
        } catch (Throwable) {
        }
    }
}
