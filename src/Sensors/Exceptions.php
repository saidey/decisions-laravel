<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use SplFileObject;
use Throwable;
use WeakMap;

class Exceptions
{
    private ?WeakMap $reported = null;

    private array $sources = [];

    private string $trace = '';

    public function __construct(private Recorder $recorder, private Users $users) {}

    public function handle(Throwable $exception): void
    {
        try {
            foreach (config('decisions.ignore.exceptions', []) as $class) {
                if (is_a($exception, $class)) {
                    return;
                }
            }
            $this->reported ??= new WeakMap;
            if (isset($this->reported[$exception])) {
                return;
            }
            $this->reported[$exception] = true;
            if ($this->trace !== $this->recorder->traceId()) {
                $this->sources = [];
                $this->trace = $this->recorder->traceId();
            }
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
                if (config('decisions.capture.source', true)) {
                    $source = $this->source($file, (int) ($frame['line'] ?? 0));
                    if ($source !== null) {
                        $entry['source'] = $source;
                    }
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

    private function source(string $file, int $line): ?array
    {
        try {
            $path = realpath($file);
            $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            $relative = $path !== false && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
            if ($path === false || $line < 1 || str_contains('/'.str_replace('\\', '/', $relative), '/vendor/')) {
                return null;
            }
            if (! array_key_exists($path, $this->sources)) {
                if (count($this->sources) >= 30) {
                    return null;
                }
                $this->sources[$path] = null;
                if (! is_file($path) || ! is_readable($path) || filesize($path) > 2 * 1024 * 1024) {
                    return null;
                }
                $this->sources[$path] = new SplFileObject($path, 'r');
            }
            $source = $this->sources[$path];
            if ($source === null) {
                return null;
            }
            $source->seek(max(0, $line - 6));
            $lines = [];
            for ($number = max(1, $line - 5); $number <= $line + 5 && ! $source->eof(); $number++) {
                $lines[$number] = mb_strcut(rtrim($source->current(), "\r\n"), 0, 8192, 'UTF-8');
                $source->next();
            }

            return $lines;
        } catch (Throwable) {
            return null;
        }
    }
}
