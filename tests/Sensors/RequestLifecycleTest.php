<?php

namespace DecisionsAi\Laravel\Tests\Sensors;

use Closure;
use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\RequestLifecycle;
use DecisionsAi\Laravel\Tests\TestCase;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RuntimeException;

class RequestLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 202)]);
    }

    public function test_controller_route_has_ordered_monotonic_phases(): void
    {
        Route::get('/controller', [LifecycleController::class, 'index'])->middleware(LifecycleMiddleware::class);
        $this->get('/controller')->assertOk();

        $record = $this->requests()[0];
        $this->assertSame(['middleware', 'controller', 'render', 'middleware', 'sending', 'terminating'], array_column($record['phases'], 'name'));
        $this->assertTimeline($record);
        $this->assertLessThanOrEqual($record['lifecycle_total_ms'], $record['ms']);
        $this->assertEqualsWithDelta($record['at'], $record['started_at'] + $record['ms'] / 1000, 0.01);
        $phases = array_column($record['phases'], null, 'name');
        $this->assertGreaterThan(0, $phases['terminating']['duration_ms']);
    }

    public function test_closure_route_is_measured_as_controller_work(): void
    {
        Route::get('/closure', fn () => ['ok' => true]);
        $this->get('/closure')->assertOk();

        $record = $this->requests()[0];
        $this->assertSame(['middleware', 'controller', 'render', 'middleware', 'sending', 'terminating'], array_column($record['phases'], 'name'));
        $this->assertTimeline($record);
        $middleware = array_values(array_filter($record['phases'], fn ($phase) => $phase['name'] === 'middleware'));
        $this->assertNotSame($middleware[0]['id'], $middleware[1]['id']);
    }

    public function test_cold_bootstrap_is_measured_once_and_not_reused_on_warm_requests(): void
    {
        Route::get('/cold', fn () => 'ok');
        $request = Request::create('/cold', server: ['REQUEST_TIME_FLOAT' => microtime(true) - 0.01]);
        $lifecycle = app(RequestLifecycle::class);
        $lifecycle->begin($request, true);
        $lifecycle->bootstrapped();
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $this->get('/cold')->assertOk();

        [$cold, $warm] = $this->requests();
        $this->assertSame('bootstrap', $cold['phases'][0]['name']);
        $this->assertSame(0.0, $cold['phases'][0]['offset_ms']);
        $this->assertGreaterThanOrEqual(10, $cold['phases'][0]['duration_ms']);
        $this->assertNotContains('bootstrap', array_column($warm['phases'], 'name'));
        $this->assertTimeline($cold);
        $this->assertTimeline($warm);
    }

    public function test_middleware_short_circuit_has_sparse_phases(): void
    {
        Route::get('/short', fn () => throw new RuntimeException('must not execute'))->middleware(ShortCircuitMiddleware::class);
        $this->get('/short')->assertStatus(202);

        $record = $this->requests()[0];
        $this->assertSame(['sending', 'terminating'], array_column($record['phases'], 'name'));
        $this->assertTimeline($record);
    }

    public function test_exception_response_keeps_only_observed_boundaries(): void
    {
        Route::get('/exception', fn () => throw new RuntimeException('controller failure'));
        $this->get('/exception')->assertStatus(500);

        $record = $this->requests()[0];
        $this->assertSame(['middleware', 'controller', 'sending', 'terminating'], array_column($record['phases'], 'name'));
        $this->assertSame(500, $record['status']);
        $this->assertTimeline($record);
        $this->assertCount(1, $this->requests());
    }

    public function test_octane_style_requests_reset_state_and_do_not_use_worker_boot_time(): void
    {
        Route::get('/warm', fn () => 'ok');
        $kernel = app(Kernel::class);
        $stale = microtime(true) - 3600;
        for ($i = 0; $i < 2; $i++) {
            $request = Request::create('/warm', server: ['REQUEST_TIME_FLOAT' => $stale]);
            app()->instance('request', $request);
            $event = (object) ['request' => $request, 'sandbox' => app()];
            Event::dispatch('Laravel\\Octane\\Events\\RequestReceived', [$event]);
            $response = $kernel->handle($request);
            Event::dispatch('Laravel\\Octane\\Events\\RequestTerminated', [$event]);
        }

        [$first, $second] = $this->requests();
        $this->assertNotSame($first['trace'], $second['trace']);
        $this->assertSame([], array_intersect(array_column($first['phases'], 'id'), array_column($second['phases'], 'id')));
        foreach ([$first, $second] as $record) {
            $this->assertNotContains('bootstrap', array_column($record['phases'], 'name'));
            $this->assertNotContains('sending', array_column($record['phases'], 'name'));
            $this->assertNotContains('terminating', array_column($record['phases'], 'name'));
            $this->assertGreaterThan($stale + 3500, $record['started_at']);
            $this->assertLessThan(1000, $record['ms']);
            $this->assertTimeline($record);
        }
    }

    public function test_explicit_octane_boundaries_reset_a_reused_request_object_even_without_termination(): void
    {
        Route::get('/reused', fn () => 'ok');
        $request = Request::create('/reused');
        $kernel = app(Kernel::class);
        $event = (object) ['request' => $request, 'sandbox' => app()];
        for ($i = 0; $i < 3; $i++) {
            Event::dispatch('Laravel\\Octane\\Events\\RequestReceived', [$event]);
            $kernel->handle($request);
            if ($i > 0) {
                Event::dispatch('Laravel\\Octane\\Events\\RequestTerminated', [$event]);
            }
        }

        $records = $this->requests();
        $this->assertCount(3, $records);
        $this->assertNull($records[0]['lifecycle_total_ms']);
        $this->assertCount(3, array_unique(array_column($records, 'trace')));
        $ids = array_column(array_merge(...array_column($records, 'phases')), 'id');
        $this->assertCount(count($ids), array_unique($ids));
        foreach (array_slice($records, 1) as $record) {
            $this->assertTimeline($record);
        }
    }

    public function test_query_and_http_children_have_request_start_offsets_and_phase_ids(): void
    {
        $http = new Factory(app('events'));
        $http->fake(['*' => $http->response('ok')]);
        Route::get('/children', function () use ($http) {
            usleep(2000);
            Event::dispatch(new QueryExecuted('select 1', [], 0.1, new Connection(null, '', '', ['name' => 'reporting', 'driver' => 'mysql'])));
            $http->get('https://api.example.test/items?token=private');

            return 'ok';
        });
        $this->get('/children')->assertOk();

        $request = $this->requests()[0];
        $controller = collect($request['phases'])->firstWhere('name', 'controller');
        foreach (['query', 'http'] as $type) {
            $record = collect($this->records())->firstWhere('t', $type);
            $this->assertSame($request['trace'], $record['trace']);
            $this->assertSame($controller['id'], $record['phase_id']);
            $this->assertGreaterThanOrEqual($controller['offset_ms'], $record['offset_ms']);
            $this->assertLessThanOrEqual($controller['offset_ms'] + $controller['duration_ms'], $record['offset_ms']);
        }
        $this->assertSame('mysql', collect($this->records())->firstWhere('t', 'query')['connection_type']);
        $this->assertSame('https://api.example.test/items?token=[redacted]', collect($this->records())->firstWhere('t', 'http')['url']);
    }

    public function test_cache_and_dispatch_children_keep_start_context(): void
    {
        Route::get('/dispatch', function () {
            Cache::store('array')->put('private:token', 'secret');
            Cache::store('array')->get('private:token');
            $job = new \stdClass;
            Event::dispatch(new JobQueueing('redis', 'default', $job, '{}', null));
            Event::dispatch(new JobQueued('redis', 'default', '1', $job, '{}', null));

            return 'ok';
        });
        $this->get('/dispatch')->assertOk();
        $request = $this->requests()[0];
        $controller = collect($request['phases'])->firstWhere('name', 'controller');
        $children = array_filter($this->records(), fn ($record) => in_array($record['t'], ['cache', 'job'], true));
        $this->assertCount(3, $children);
        foreach ($children as $record) {
            $this->assertSame($controller['id'], $record['phase_id']);
            $this->assertGreaterThanOrEqual($controller['offset_ms'], $record['offset_ms']);
        }
        $job = collect($children)->firstWhere('t', 'job');
        $this->assertSame('queued', $job['status']);
        $this->assertStringNotContainsString('private:token', json_encode($children));
    }

    public function test_bulk_cache_children_use_the_operation_start_instead_of_completion(): void
    {
        Event::listen([WritingManyKeys::class, RetrievingManyKeys::class], function () {
            usleep(2000);
        });
        Route::get('/bulk-cache', function () {
            Cache::store('array')->putMany(['first' => 'a', 'second' => 'b'], 60);
            Cache::store('array')->many(['first', 'missing']);

            return 'ok';
        });
        $this->get('/bulk-cache')->assertOk();
        $request = $this->requests()[0];
        $controller = collect($request['phases'])->firstWhere('name', 'controller');
        $children = array_filter($this->records(), fn ($record) => $record['t'] === 'cache');
        $this->assertCount(4, $children);
        foreach ($children as $record) {
            $this->assertSame($controller['id'], $record['phase_id']);
            $completionOffset = ($record['at'] - $request['started_at']) * 1000;
            $this->assertGreaterThanOrEqual(1.5, $completionOffset - $record['offset_ms']);
        }
    }

    public function test_nested_and_repeated_response_conversion_is_recorded_once(): void
    {
        Route::get('/render', fn () => new class implements Responsable
        {
            public function toResponse($request)
            {
                return app('router')->prepareResponse($request, 'ok');
            }
        });
        $this->get('/render')->assertOk();

        $record = $this->requests()[0];
        $this->assertCount(1, array_filter($record['phases'], fn ($phase) => $phase['name'] === 'render'));
        $this->assertTimeline($record);
    }

    public function test_request_record_is_deferred_and_emitted_once_after_termination_even_with_early_flush(): void
    {
        Route::get('/once', function () {
            app(Recorder::class)->record(['t' => 'log', 'message' => 'early flush']);
            app(Recorder::class)->flush();

            return 'ok';
        });
        $request = Request::create('/once');
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $this->assertSame([], $this->requests());
        $handled = microtime(true);
        usleep(2000);
        Event::dispatch(new RequestHandled($request, $response));
        $kernel->terminate($request, $response);
        app(RequestLifecycle::class)->finish();
        Event::dispatch(new RequestHandled($request, $response));
        $records = $this->requests();
        $this->assertCount(1, $records);
        $this->assertLessThanOrEqual($handled, $records[0]['at']);
        $sending = collect($records[0]['phases'])->firstWhere('name', 'sending');
        $this->assertGreaterThanOrEqual(2, $sending['duration_ms']);
    }

    public function test_missing_termination_falls_back_once_at_next_request_without_fabricated_final_phases(): void
    {
        Route::get('/fallback', fn () => 'ok');
        $request = Request::create('/fallback');
        $kernel = app(Kernel::class);
        $kernel->handle($request);
        $this->assertSame([], $this->requests());
        $this->get('/fallback')->assertOk();
        [$partial, $complete] = $this->requests();
        $this->assertNull($partial['lifecycle_total_ms']);
        $this->assertNotContains('sending', array_column($partial['phases'], 'name'));
        $this->assertNotContains('terminating', array_column($partial['phases'], 'name'));
        $this->assertNotSame($partial['trace'], $complete['trace']);
        app(RequestLifecycle::class)->finish(false);
        $this->assertCount(2, $this->requests());
    }

    public function test_shutdown_fallback_keeps_a_partial_snapshot_and_original_handled_timestamp(): void
    {
        Route::get('/shutdown', fn () => 'ok');
        app(Kernel::class)->handle(Request::create('/shutdown'));
        $handled = microtime(true);
        $this->assertSame([], $this->requests());
        app(RequestLifecycle::class)->finish(false);
        app(RequestLifecycle::class)->finish(false);

        $records = $this->requests();
        $this->assertCount(1, $records);
        $this->assertNull($records[0]['lifecycle_total_ms']);
        $this->assertLessThanOrEqual($handled, $records[0]['at']);
    }

    public function test_child_context_is_absent_outside_requests_and_after_finalization(): void
    {
        $lifecycle = app(RequestLifecycle::class);
        $this->assertSame([], $lifecycle->context());
        Route::get('/done', fn () => 'ok');
        $this->get('/done')->assertOk();
        $this->assertSame([], $lifecycle->context());
        Event::dispatch(new QueryExecuted('select 1', [], 0, new Connection(null, '', '', ['driver' => 'sqlite'])));
        $query = collect($this->records())->firstWhere('t', 'query');
        $this->assertArrayNotHasKey('offset_ms', $query);
        $this->assertArrayNotHasKey('phase_id', $query);
    }

    private function assertTimeline(array $record): void
    {
        $previousEnd = 0;
        $ids = [];
        foreach ($record['phases'] as $phase) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'offset_ms', 'duration_ms'], array_keys($phase));
            $this->assertIsString($phase['id']);
            $this->assertNotContains($phase['id'], $ids);
            $this->assertGreaterThanOrEqual($previousEnd, $phase['offset_ms']);
            $this->assertGreaterThanOrEqual(0, $phase['duration_ms']);
            $previousEnd = $phase['offset_ms'] + $phase['duration_ms'];
            $ids[] = $phase['id'];
        }
        $this->assertGreaterThanOrEqual($previousEnd, $record['lifecycle_total_ms']);
        $this->assertLessThanOrEqual(65536, strlen(json_encode($record)));
    }

    private function requests(): array
    {
        return array_values(array_filter($this->records(), fn ($record) => $record['t'] === 'request'));
    }

    private function records(): array
    {
        app(Recorder::class)->flush();

        return $this->transportHttp()->recorded()->flatMap(fn ($pair) => json_decode(gzdecode($pair[0]->body()), true, flags: JSON_THROW_ON_ERROR)['records'])->all();
    }
}

class LifecycleController
{
    public function index(): string
    {
        return 'ok';
    }
}

class LifecycleMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        usleep(1000);
    }
}

class ShortCircuitMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return response('short', 202);
    }
}
