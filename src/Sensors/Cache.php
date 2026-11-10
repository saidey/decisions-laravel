<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\Recorder;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Throwable;

class Cache
{
    public function __construct(private Recorder $recorder) {}

    public function handle(CacheHit|CacheMissed|KeyWritten|KeyForgotten $event): void
    {
        try {
            $this->recorder->record([
                't' => 'cache',
                'ms' => 0.0,
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
}
