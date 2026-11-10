<?php

namespace DecisionsAi\Laravel;

use Throwable;

class Recorder
{
    private array $records = [];

    private string $trace = '';

    private int $dropped = 0;

    private bool $flushing = false;

    private bool $enabled = false;

    public function __construct(
        private Transport $transport,
        private Redactor $redactor,
        private array $config = [],
    ) {
        try {
            $this->enabled = (bool) ($config['enabled'] ?? ! empty($config['token'])) && ! empty($config['token']);
            $this->startTrace();
        } catch (Throwable) {
            $this->enabled = false;
        }
    }

    public function record(array $record): void
    {
        try {
            if (! $this->enabled || $this->flushing) {
                return;
            }

            if (($record['t'] ?? null) !== 'schedule') {
                $record['trace'] = $this->trace;
            }
            $record['at'] = (float) ($record['at'] ?? microtime(true));
            $record = $this->redactor->redact($record);
            if ($record === []) {
                $this->dropped++;

                return;
            }

            $this->records[] = $record;
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
            $this->transport->send([
                'v' => 1,
                'environment' => $this->config['environment'] ?? 'production',
                'deploy' => $this->config['deploy'] ?? null,
                'server' => gethostname() ?: 'unknown',
                'records' => $records,
            ]);
        } catch (Throwable) {
            $this->dropped += count($records);
        } finally {
            if ($started) {
                $this->flushing = false;
            }
        }
    }

    public function startTrace(): string
    {
        try {
            $this->flush();
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

    public function droppedRecords(): int
    {
        try {
            return $this->dropped + $this->transport->droppedRecords();
        } catch (Throwable) {
            return $this->dropped;
        }
    }
}
