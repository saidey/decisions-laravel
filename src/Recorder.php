<?php

namespace DecisionsAi\Laravel;

use Illuminate\Support\Str;
use Throwable;
use WeakMap;

class Recorder
{
    private array $records = [];

    private string $trace = '';

    private int $dropped = 0;

    private bool $flushing = false;

    private bool $enabled = false;

    private bool $ignored = false;

    private ?string $traceType = null;

    private ?WeakMap $jobTraces = null;

    private array $metadata = [];

    private int $recordBytes = 0;

    private int $batchBytes = 0;

    public function __construct(
        private Transport $transport,
        private Redactor $redactor,
        private array $config = [],
    ) {
        try {
            $this->enabled = (bool) ($config['enabled'] ?? ! empty($config['token'])) && ! empty($config['token']);
            $this->metadata = [
                'v' => 1,
                'environment' => mb_strcut((string) ($config['environment'] ?? 'production'), 0, 8192, 'UTF-8'),
                'deploy' => isset($config['deploy']) ? mb_strcut((string) $config['deploy'], 0, 8192, 'UTF-8') : null,
                'server' => gethostname() ?: 'unknown',
            ];
            $this->batchBytes = strlen(json_encode([...$this->metadata, 'records' => []], Transport::JSON_FLAGS));
            $this->startTrace();
        } catch (Throwable) {
            $this->enabled = false;
        }
    }

    public function record(array $record, ?string $trace = null): void
    {
        try {
            if (! $this->enabled || $this->flushing || $this->ignoreCurrentTrace()) {
                return;
            }

            $type = $record['t'] ?? '';
            if ($type === 'request' && $this->matchesIgnore('paths', $record['path'] ?? '')) {
                $this->ignored = true;
                $this->records = [];
                $this->recordBytes = 0;

                return;
            }
            if ($type === 'job' && ($record['status'] ?? null) === 'queued' && $this->matchesIgnore('jobs', $record['class'] ?? '')) {
                return;
            }
            if ($type === 'exception') {
                foreach ($this->config['ignore']['exceptions'] ?? [] as $class) {
                    if (is_a($record['class'] ?? '', $class, true)) {
                        return;
                    }
                }
            }
            $rate = (float) ($this->config['sample'][$type] ?? 1.0);
            if ($rate <= 0 || ($rate < 1 && random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX >= $rate)) {
                return;
            }

            if (($record['t'] ?? null) !== 'schedule') {
                $record['trace'] = $trace ?? $this->trace;
            }
            $record['at'] = (float) ($record['at'] ?? microtime(true));
            $record = $this->redactor->redact($record);
            if ($record === []) {
                $this->dropped++;

                return;
            }

            $bytes = strlen(json_encode($record, Transport::JSON_FLAGS));
            if ($bytes > 65536) {
                $this->dropped++;

                return;
            }
            if ($this->batchBytes + $this->recordBytes + $bytes + count($this->records) > Transport::MAX_BATCH_BYTES) {
                $this->flush();
            }
            $this->records[] = $record;
            $this->recordBytes += $bytes;
            if (count($this->records) >= max(1, (int) ($this->config['buffer'] ?? 1000))) {
                $this->flush();
            }
        } catch (Throwable) {
            $this->dropped++;
        }
    }

    public function flush(): void
    {
        $records = [];
        $started = false;

        try {
            if (! $this->enabled || $this->flushing || $this->records === []) {
                return;
            }

            $this->flushing = true;
            $started = true;
            $records = $this->records;
            $this->records = [];
            $this->recordBytes = 0;
            if (! $this->ignored) {
                $this->transport->send([...$this->metadata, 'records' => $records]);
            }
        } catch (Throwable) {
            $this->dropped += count($records);
        } finally {
            if ($started) {
                $this->flushing = false;
            }
        }
    }

    public function startTrace(?string $type = null, ?string $name = null): string
    {
        try {
            $this->flush();
            $this->traceType = $type;
            $this->ignored = $name !== null && $this->matchesIgnore(match ($type) {
                'command' => 'commands',
                'job' => 'jobs',
                default => 'paths',
            }, $name);
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
            $hex = bin2hex($bytes);
            $this->trace = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
        } catch (Throwable) {
            // Keep the existing trace if a new identity cannot be generated.
        }

        return $this->trace;
    }

    public function traceId(): string
    {
        return $this->trace;
    }

    public function startJobTrace(object $job, ?string $name): void
    {
        $this->jobTraces ??= new WeakMap;
        $parent = [$this->trace, $this->traceType, $this->ignoreCurrentTrace()];
        $this->startTrace('job', $name);
        $this->ignored = $this->ignored || $parent[2];
        $this->jobTraces[$job] = $parent;
    }

    public function finishJobTrace(object $job): void
    {
        if ($this->jobTraces === null || ! isset($this->jobTraces[$job])) {
            return;
        }
        $this->flush();
        [$this->trace, $this->traceType, $this->ignored] = $this->jobTraces[$job];
        unset($this->jobTraces[$job]);
    }

    private function matchesIgnore(string $type, string $name): bool
    {
        foreach ($this->config['ignore'][$type] ?? [] as $pattern) {
            if (Str::is($type === 'paths' ? ltrim($pattern, '/') : $pattern, $type === 'paths' ? ltrim($name, '/') : $name)) {
                return true;
            }
        }

        return false;
    }

    private function ignoreCurrentTrace(): bool
    {
        if (! $this->ignored && in_array($this->traceType, [null, 'request'], true)
            && ! empty($this->config['ignore']['paths']) && app()->bound('request')) {
            $this->ignored = $this->matchesIgnore('paths', app('request')->getPathInfo());
        }

        return $this->ignored;
    }

    public function droppedRecords(): int
    {
        try {
            return $this->dropped + $this->transport->droppedRecords();
        } catch (Throwable) {
            return $this->dropped;
        }
    }
}
