<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\RequestLifecycle;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Events\WritingManyKeys;
use Throwable;

class Cache
{
    public function __construct(private Recorder $recorder, private RequestLifecycle $lifecycle) {}

    public function start(RetrievingKey|RetrievingManyKeys|WritingKey|WritingManyKeys|ForgettingKey $event): void
    {
        try {
            $op = match (true) {
                $event instanceof RetrievingKey, $event instanceof RetrievingManyKeys => 'get',
                $event instanceof WritingKey, $event instanceof WritingManyKeys => 'write',
                default => 'forget',
            };
            foreach ($event->keys ?? [$event->key] as $key) {
                $this->lifecycle->remember($this->key($event, $op, (string) $key));
            }
        } catch (Throwable) {
        }
    }

    public function handle(CacheHit|CacheMissed|KeyWritten|KeyForgotten $event): void
    {
        try {
            $op = match (true) {
                $event instanceof CacheHit, $event instanceof CacheMissed => 'get',
                $event instanceof KeyWritten => 'write',
                default => 'forget',
            };
            $this->recorder->record([
                't' => 'cache',
                'ms' => 0.0,
                ...$this->lifecycle->take($this->key($event, $op)),
                'key' => $event->key,
                'op' => match (true) {
                    $event instanceof CacheHit => 'hit',
                    $event instanceof CacheMissed => 'miss',
                    $event instanceof KeyWritten => 'write',
                    default => 'forget',
                },
                'store' => $event->storeName ?? config('cache.default'),
            ]);
        } catch (Throwable) {
        }
    }

    private function key(object $event, string $op, ?string $key = null): string
    {
        return 'cache:'.$op.':'.hash('sha256', serialize([$event->storeName, $key ?? $event->key, $event->tags]));
    }
}
