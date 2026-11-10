<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Redactor;
use Illuminate\Database\QueryException;
use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;
use WeakMap;

class Logs
{
    private ?WeakMap $recorded = null;

    public function __construct(private Recorder $recorder, private Redactor $redactor) {}

    public function __invoke(Logger $logger): void
    {
        try {
            $monolog = $logger->getLogger();
            if (! method_exists($monolog, 'pushProcessor')) {
                return;
            }
            $processor = [$this, 'process'];
            $processors = $monolog->getProcessors();
            // Stacks inherit their channels' processors. Keep one monitoring copy.
            foreach ($processors as $_) {
                $monolog->popProcessor();
            }
            foreach (array_reverse($processors) as $existing) {
                if ($existing !== $processor) {
                    $monolog->pushProcessor($existing);
                }
            }
            $monolog->pushProcessor($processor);
        } catch (Throwable) {
        }
    }

    public function process(LogRecord $record): LogRecord
    {
        try {
            $this->recorded ??= new WeakMap;
            // Inherited stack processors may receive clones with the same timestamp object.
            if (isset($this->recorded[$record->datetime])) {
                return $record;
            }
            $this->recorded[$record->datetime] = true;
            $exception = $record->context['exception'] ?? null;
            $bindings = (bool) config('decisions.capture.bindings', false);
            $message = $record->message;
            if ($exception instanceof QueryException) {
                $message = $this->redactor->exceptionMessage($exception, $bindings);
            } elseif (! $bindings && str_contains($message, '(Connection: ') && str_contains($message, 'SQL: ')) {
                // A stringified query exception no longer exposes its SQL template.
                $message = 'Database query failed [REDACTED]';
            }
            $this->recorder->record([
                't' => 'log',
                'level' => strtolower($record->level->name),
                'channel' => $record->channel,
                'message' => $this->redactor->scrubValue($message),
                'context' => $record->context,
            ]);
        } catch (Throwable) {
        }

        return $record;
    }
}
