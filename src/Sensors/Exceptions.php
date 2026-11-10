<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Throwable;
use WeakMap;

class Exceptions
{
    private ?WeakMap $reported = null;

    public function __construct(private Recorder $recorder, private Users $users) {}

    public function handle(Throwable $exception): void
    {
        try {
            $this->reported ??= new WeakMap;
            if (isset($this->reported[$exception])) {
                return;
            }
            $this->reported[$exception] = true;
            $frames = [];
            $trace = [['file' => $exception->getFile(), 'line' => $exception->getLine()]];
            foreach (array_slice([...$trace, ...$exception->getTrace()], 0, 30) as $frame) {
                $file = $frame['file'] ?? '';
                $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
                $relative = str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
                $inApp = str_starts_with($file, $base) && ! str_starts_with($relative, 'vendor'.DIRECTORY_SEPARATOR);
                $entry = [
                    'file' => $relative,
                    'line' => $frame['line'] ?? null,
                    'function' => ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''),
                    'in_app' => $inApp,
                ];
                if (config('decisions.capture.source', true) && is_file($file) && is_readable($file)) {
                    $lines = file($file, FILE_IGNORE_NEW_LINES);
                    $source = [];
                    $line = (int) ($frame['line'] ?? 0);
                    for ($number = max(1, $line - 5); $number <= min(count($lines), $line + 5); $number++) {
                        $source[$number] = $lines[$number - 1];
                    }
                    $entry['source'] = $source;
                }
                $frames[] = $entry;
            }
            $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            $file = $exception->getFile();
            $this->recorder->record([
                't' => 'exception',
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => str_starts_with($file, $base) ? substr($file, strlen($base)) : $file,
                'line' => $exception->getLine(),
                'trace_frames' => $frames,
                'handled' => false,
                ...$this->users->identity(true),
            ]);
        } catch (Throwable) {
        }
    }
}
