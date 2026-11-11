<?php

namespace DecisionsAi\Laravel;

use Illuminate\Http\Request;
use Throwable;
use WeakMap;

class RequestLifecycle
{
    private ?Request $request = null;

    private float $startedAt = 0;

    private int $origin = 0;

    private float $initialOffset = 0;

    private string $trace = '';

    private array $phases = [];

    private ?array $current = null;

    private ?array $record = null;

    private bool $finished = false;

    private bool $cold = false;

    private bool $octane = false;

    private bool $actionReturned = false;

    private int $renderDepth = 0;

    private ?WeakMap $objects = null;

    private array $operations = [];

    private int $pendingOperations = 0;

    public function __construct(private Recorder $recorder) {}

    public function begin(Request $request, bool $cold = false, bool $octane = false): void
    {
        try {
            if ($this->request === $request && ! $this->finished && (! $octane || $this->record === null)) {
                $this->octane = $this->octane || $octane;

                return;
            }
            $this->finish(false);
            $now = microtime(true);
            $this->request = $request;
            // A warm worker's server timestamp may still refer to process startup.
            $started = $cold ? $request->server('REQUEST_TIME_FLOAT', $now) : $now;
            $this->startedAt = is_numeric($started) ? min($now, (float) $started) : $now;
            $this->origin = hrtime(true);
            $this->initialOffset = max(0, ($now - $this->startedAt) * 1000);
            $this->phases = [];
            $this->current = null;
            $this->record = null;
            $this->finished = false;
            $this->cold = $cold;
            $this->octane = $octane;
            $this->actionReturned = false;
            $this->renderDepth = 0;
            $this->objects = new WeakMap;
            $this->operations = [];
            $this->pendingOperations = 0;
            $this->trace = $this->recorder->startTrace('request', $request->getPathInfo());
            $this->open($cold ? 'bootstrap' : 'middleware_in', $cold ? 0 : $this->elapsed());
        } catch (Throwable) {
            $this->current = null;
        }
    }

    public function bootstrapped(): void
    {
        try {
            if ($this->active() && $this->cold && ($this->current['key'] ?? null) === 'bootstrap') {
                $this->close();
                $this->open('middleware_in');
            }
        } catch (Throwable) {
        }
    }

    public function actionStarted(): void
    {
        try {
            if (! $this->active() || ($this->current['key'] ?? null) !== 'middleware_in') {
                return;
            }
            $this->close();
            $this->open('controller');
        } catch (Throwable) {
        }
    }

    public function actionFinished(bool $returned): void
    {
        try {
            if (($this->current['key'] ?? null) === 'controller') {
                $this->close();
                $this->actionReturned = $returned;
            }
        } catch (Throwable) {
        }
    }

    public function preparingResponse(Request $request): void
    {
        try {
            if ($this->request !== $request || ! $this->active() || ! $this->actionReturned) {
                return;
            }
            if ($this->renderDepth++ === 0) {
                $this->open('render');
            }
        } catch (Throwable) {
        }
    }

    public function responsePrepared(Request $request): void
    {
        try {
            if ($this->request === $request && $this->renderDepth > 0 && --$this->renderDepth === 0) {
                $this->close();
                $this->actionReturned = false;
                $this->open('middleware_out');
            }
        } catch (Throwable) {
        }
    }

    public function handled(Request $request, array|callable $record): void
    {
        try {
            if ($this->request !== $request) {
                $this->begin($request);
                // No action or middleware boundaries were observed for this request.
                $this->current = null;
            }
            if ($this->finished || $this->record !== null) {
                return;
            }
            $offset = $this->elapsed();
            $at = microtime(true);
            if (($this->current['key'] ?? null) === 'middleware_out') {
                $this->close($offset);
            } else {
                $this->current = null;
            }
            if (! $this->octane) {
                $this->open('sending', $offset);
            }
            $this->record = [
                ...(is_callable($record) ? $record() : $record),
                'at' => $at,
                'ms' => $offset,
                'started_at' => $this->startedAt,
            ];
        } catch (Throwable) {
        }
    }

    public function terminating(): void
    {
        try {
            if (! $this->active() || $this->record === null || ($this->current['key'] ?? null) === 'terminating') {
                return;
            }
            if (($this->current['key'] ?? null) === 'sending') {
                $this->close();
            }
            $this->open('terminating');
        } catch (Throwable) {
        }
    }

    public function finish(bool $complete = true): void
    {
        try {
            if (! $this->active()) {
                return;
            }
            $this->finished = true;
            if ($complete && ($this->current['key'] ?? null) === 'terminating') {
                $this->close();
            }
            $this->current = null;
            if ($this->record !== null) {
                $record = [
                    ...$this->record,
                    'lifecycle_total_ms' => $complete ? $this->elapsed() : null,
                    'phases' => $this->phases,
                ];
                $this->record = null;
                $this->recorder->record($record, $this->trace);
                $this->recorder->flush();
            }
            $this->objects = null;
            $this->operations = [];
            $this->pendingOperations = 0;
        } catch (Throwable) {
        }
    }

    public function context(float $durationMs = 0): array
    {
        try {
            if (! $this->active()) {
                return [];
            }
            $offset = max(0, $this->elapsed() - max(0, $durationMs));
            $phaseId = null;
            foreach ($this->phases as $phase) {
                if ($offset >= $phase['offset_ms'] && $offset <= $phase['offset_ms'] + $phase['duration_ms']) {
                    $phaseId = $phase['id'];
                }
            }
            // Inbound middleware is only distinguishable once the action is entered.
            if ($this->current !== null && $this->current['key'] !== 'middleware_in' && $offset >= $this->current['offset_ms']) {
                $phaseId = $this->current['id'];
            }

            return ['offset_ms' => $offset, 'phase_id' => $phaseId];
        } catch (Throwable) {
            return [];
        }
    }

    public function remember(object|string $key): void
    {
        try {
            if (! $this->active()) {
                return;
            }
            if (is_object($key)) {
                $this->objects[$key] = $this->context();
            } elseif ($this->pendingOperations < 1000) {
                $this->operations[$key][] = $this->context();
                $this->pendingOperations++;
            }
        } catch (Throwable) {
        }
    }

    public function take(object|string $key, float $durationMs = 0): array
    {
        try {
            if (! $this->active()) {
                return [];
            }
            if (is_object($key) && isset($this->objects[$key])) {
                $context = $this->objects[$key];
                unset($this->objects[$key]);

                return $context;
            }
            if (is_string($key) && ! empty($this->operations[$key])) {
                $context = array_pop($this->operations[$key]);
                $this->pendingOperations--;
                if ($this->operations[$key] === []) {
                    unset($this->operations[$key]);
                }

                return $context;
            }

            return $this->context($durationMs);
        } catch (Throwable) {
            return [];
        }
    }

    private function active(): bool
    {
        return $this->request !== null && ! $this->finished;
    }

    private function elapsed(): float
    {
        return $this->initialOffset + max(0, (hrtime(true) - $this->origin) / 1_000_000);
    }

    private function open(string $key, ?float $offset = null): void
    {
        $this->current = [
            'key' => $key,
            'id' => $this->trace.':'.$key,
            'name' => in_array($key, ['middleware_in', 'middleware_out'], true) ? 'middleware' : $key,
            'offset_ms' => $offset ?? $this->elapsed(),
        ];
    }

    private function close(?float $end = null): void
    {
        if ($this->current === null) {
            return;
        }
        $phase = $this->current;
        unset($phase['key']);
        $phase['duration_ms'] = max(0, ($end ?? $this->elapsed()) - $phase['offset_ms']);
        $this->phases[] = $phase;
        $this->current = null;
    }
}
