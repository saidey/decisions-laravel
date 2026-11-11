<?php

namespace DecisionsAi\Laravel\Tests\Sensors;

use DecisionsAi\Laravel\RequestLifecycle;
use DecisionsAi\Laravel\Tests\TestCase;
use WeakReference;

class LifecycleLeakTest extends TestCase
{
    public function test_shutdown_hook_does_not_keep_the_lifecycle_alive_after_the_app_is_gone(): void
    {
        $lifecycle = WeakReference::create($this->app->make(RequestLifecycle::class));
        $this->assertNotNull($lifecycle->get());

        $this->app->forgetInstance(RequestLifecycle::class);
        gc_collect_cycles();

        $this->assertNull($lifecycle->get());
    }
}
