<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

class Logs
{
    public function __construct(private Recorder $recorder) {}

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
            $this->recorder->record([
                't' => 'log',
                'level' => strtolower($record->level->name),
                'channel' => $record->channel,
                'message' => $record->message,
                'context' => $record->context,
            ]);
        } catch (Throwable) {
        }

        return $record;
    }
}
