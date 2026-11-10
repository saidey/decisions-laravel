<?php

namespace DecisionsAi\Laravel\Tests\Sensors;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Redactor;
use DecisionsAi\Laravel\Sensors\Exceptions;
use DecisionsAi\Laravel\Tests\TestCase;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request as ServerRequest;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Email;

class SensorsTest extends TestCase
{
    private array $scheduleStamps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 202)]);
        config(['decisions.deploy' => 'sensors-'.bin2hex(random_bytes(8))]);
    }

    protected function tearDown(): void
    {
        foreach ($this->scheduleStamps as $stamp) {
            if (is_file($stamp)) {
                unlink($stamp);
            }
        }
        parent::tearDown();
    }

    public function test_route_records_pattern_status_and_user_id(): void
    {
        Route::get('/orders/{order}', fn () => response('ok', 201));
        $this->actingAs(new GenericUser(['id' => 42, 'name' => 'Private', 'email' => 'private@example.test']));
        $this->get('/orders/9')->assertStatus(201);

        $record = $this->records('request')[0];
        $this->assertShape('request', $record);
        $this->assertSame('/orders/{order}', $record['route']);
        $this->assertSame('/orders/{order}', $record['path']);
        $this->assertSame(201, $record['status']);
        $this->assertSame('42', $record['user']);
        $this->assertGreaterThanOrEqual(0, $record['ms']);
        $this->assertCount(1, $this->records('request'));
    }

    public function test_failed_job_records_failure_and_exception_before_flush(): void
    {
        $job = $this->job();
        Event::dispatch(new JobProcessing('sync', $job));
        Event::dispatch(new JobFailed('sync', $job, new RuntimeException('job failed')));

        $record = $this->records('job')[0];
        $this->assertShape('job', $record);
        $this->assertSame('failed', $record['status']);
        $this->assertSame('App\\Jobs\\Sync', $record['class']);
        $this->assertSame('default', $record['queue']);
        $this->assertSame(2, $record['attempt']);
        $exception = $this->records('exception')[0];
        $this->assertSame('job failed', $exception['message']);
        $this->assertSame($record['trace'], $exception['trace']);
        $this->assertSame('exception', $this->batches()[0]['records'][1]['t']);
    }

    public function test_queued_processed_and_released_job_lifecycles(): void
    {
        Event::dispatch(new JobQueued('sync', 'default', '1', new \stdClass, '{}', null));
        $this->assertSame([], $this->records('job'));
        $job = $this->job();
        Event::dispatch(new JobProcessing('sync', $job));
        Event::dispatch(new JobProcessed('sync', $job));
        Event::dispatch(new JobProcessing('sync', $job));
        Event::dispatch(new JobReleasedAfterException('sync', $job));
        $records = $this->records('job');
        $this->assertSame(['processed', 'released'], array_column($records, 'status'));
        $this->assertNotSame($records[0]['trace'], $records[1]['trace']);
    }

    public function test_sensor_failure_does_not_escape_to_host(): void
    {
        $request = new class extends ServerRequest
        {
            public function route($param = null, $default = null)
            {
                throw new RuntimeException('forced sensor failure');
            }
        };
        Event::dispatch(new RequestHandled($request, response('ok')));
        $this->assertSame([], $this->records('request'));
    }

    public function test_exception_reportable_hook_and_user_privacy(): void
    {
        $this->actingAs(new GenericUser(['id' => 42, 'name' => 'Private', 'email' => 'private@example.test']));
        app(ExceptionHandler::class)->report(new RuntimeException('reported'));
        $record = $this->records('exception')[0];
        $this->assertShape('exception', $record);
        $this->assertSame('42', $record['user']);
        $this->assertArrayNotHasKey('user_email', $record);
        $this->assertArrayNotHasKey('user_name', $record);
        $this->assertFalse($record['handled']);
        $this->assertLessThanOrEqual(30, count($record['trace_frames']));

        config(['decisions.user_details' => true]);
        app()->forgetInstance(Redactor::class);
        app()->forgetInstance(Recorder::class);
        app()->forgetInstance(Exceptions::class);
        app(Exceptions::class)->handle(new RuntimeException('details'));
        $record = $this->records('exception')[1];
        $this->assertShape('exception', $record, ['user_name', 'user_email']);
        $this->assertSame('Private', $record['user_name']);
        $this->assertSame('private@example.test', $record['user_email']);
    }

    public function test_exception_frames_source_and_app_boundary(): void
    {
        $appFile = app_path('MonitoringSource.php');
        $vendorFile = base_path('vendor/MonitoringSource.php');
        foreach ([$appFile, $vendorFile] as $file) {
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, "<?php\n".str_repeat("// source\n", 8)."throw new \\RuntimeException('source');\n".str_repeat("// source\n", 8));
        }
        try {
            foreach ([$appFile, $vendorFile] as $file) {
                try {
                    require $file;
                } catch (RuntimeException $exception) {
                    app(Exceptions::class)->handle($exception);
                }
            }
            $records = $this->records('exception');
            $frame = $records[0]['trace_frames'][0];
            $this->assertTrue($frame['in_app']);
            $this->assertSame(10, $frame['line']);
            $this->assertSame(range(5, 15), array_keys($frame['source']));
            $this->assertFalse($records[1]['trace_frames'][0]['in_app']);
            $this->assertArrayNotHasKey('source', $records[1]['trace_frames'][0]);
            config(['decisions.capture.source' => false]);
            app(Exceptions::class)->handle(new RuntimeException('no source'));
            $this->assertArrayNotHasKey('source', $this->records('exception')[2]['trace_frames'][0]);
        } finally {
            unlink($appFile);
            unlink($vendorFile);
        }
    }

    public function test_queries_do_not_capture_bindings_unless_enabled(): void
    {
        $connection = new Connection(null, 'database', '', ['name' => 'mysql']);
        Event::dispatch(new QueryExecuted('select * from orders where id = ?', ['private-value'], 12.5, $connection));
        $record = $this->records('query')[0];
        $this->assertShape('query', $record);
        $this->assertSame('select * from orders where id = ?', $record['sql']);
        $this->assertSame('mysql', $record['connection']);
        $this->assertSame(12.5, $record['ms']);
        $this->assertArrayNotHasKey('bindings', $record);
        $this->assertStringNotContainsString('private-value', json_encode($record));
        config(['decisions.capture.bindings' => true]);
        Event::dispatch(new QueryExecuted('select ?', [12345], 1, $connection));
        $this->assertSame('select 12345', $this->records('query')[1]['sql']);
        $this->assertShape('query', $this->records('query')[1]);
    }

    public function test_query_records_application_callsite_without_a_database(): void
    {
        $file = app_path('MonitoringQuery.php');
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, "<?php\nreturn function (\$event) {\n    event(\$event);\n};\n");
        try {
            $dispatch = require $file;
            $dispatch(new QueryExecuted('select 1', [], 1, new Connection(null, '', '', ['name' => 'mysql'])));
            $record = $this->records('query')[0];
            $this->assertSame('app/MonitoringQuery.php', $record['file']);
            $this->assertSame(3, $record['line']);
        } finally {
            unlink($file);
        }
    }

    public function test_schedule_reports_first_tick_hourly_and_on_new_deploy(): void
    {
        app(Schedule::class)->command('orders:sync')->cron('*/5 * * * *')->timezone('UTC');
        $this->tick('schedule:run');
        $this->tick('schedule:work');
        $records = $this->records('schedule');
        $this->assertCount(1, $records);
        $this->assertShape('schedule', $records[0]);
        $task = collect($records[0]['tasks'])->first(fn ($task) => str_contains($task['command'], 'orders:sync'));
        $this->assertSame('*/5 * * * *', $task['cron']);
        $this->assertSame('UTC', $task['timezone']);
        $stamp = storage_path('framework/decisions-schedule-'.config('decisions.deploy'));
        $this->assertFileExists($stamp);
        touch($stamp, time() - 3540);
        $this->tick('schedule:run');
        $this->assertCount(1, $this->records('schedule'));
        touch($stamp, time() - 3660);
        $this->tick('schedule:run');
        $this->assertCount(2, $this->records('schedule'));
        config(['decisions.deploy' => 'next-'.bin2hex(random_bytes(8))]);
        $this->tick('schedule:run');
        $this->assertCount(3, $this->records('schedule'));
    }

    public function test_commands_and_scheduled_tasks_match_wire_format(): void
    {
        $this->tick('orders:sync', 7);
        $record = $this->records('command')[0];
        $this->assertShape('command', $record);
        $this->assertSame('orders:sync', $record['name']);
        $this->assertSame(7, $record['exit']);
        $task = app(Schedule::class)->command('orders:sync')->cron('*/5 * * * *');
        Event::dispatch(new ScheduledTaskFinished($task, 0.25));
        $failedTask = app(Schedule::class)->command('orders:fail');
        Event::dispatch(new ScheduledTaskFailed($failedTask, new RuntimeException('task failed'), 0.5));
        $records = $this->records('task');
        $this->assertShape('task', $records[0]);
        $this->assertSame(['ok', 'failed'], array_column($records, 'status'));
        $this->assertSame([250.0, 500.0], array_column($records, 'ms'));
        $this->assertSame('*/5 * * * *', $records[0]['cron']);
    }

    public function test_callback_tasks_have_command_labels(): void
    {
        $task = app(Schedule::class)->call(fn () => null)->everyMinute();
        $this->tick('schedule:run');
        $tasks = $this->records('schedule')[0]['tasks'];
        $this->assertContains(['command' => 'Callback', 'cron' => '* * * * *', 'timezone' => 'UTC'], $tasks);
        Event::dispatch(new ScheduledTaskFinished($task, 0.1));
        $this->assertSame('Callback', $this->records('task')[0]['command']);
    }

    public function test_cache_mail_notifications_and_logs_match_wire_format(): void
    {
        Event::dispatch(new CacheHit('redis', 'orders:9', 'private'));
        Event::dispatch(new CacheMissed('redis', 'orders:9'));
        Event::dispatch(new KeyWritten('redis', 'orders:9', 'private', 60));
        Event::dispatch(new KeyForgotten('redis', 'orders:9'));
        $records = $this->records('cache');
        $this->assertSame(['hit', 'miss', 'write', 'forget'], array_column($records, 'op'));
        foreach ($records as $record) {
            $this->assertShape('cache', $record);
            $this->assertSame('redis', $record['store']);
        }
        $email = (new Email)->from('sender@example.test')->to('to@example.test')->subject('Private')->text('private');
        Event::dispatch(new MessageSent(new SentMessage(new SymfonySentMessage($email, Envelope::create($email))), ['__laravel_mailable' => 'App\\Mail\\Invoice']));
        $notification = new Notification;
        Event::dispatch(new NotificationSent(new \stdClass, $notification, 'mail'));
        Log::error('text', ['password' => 'secret']);
        $mail = $this->records('mail')[0];
        $this->assertShape('mail', $mail);
        $this->assertSame('App\\Mail\\Invoice', $mail['class']);
        $this->assertSame(1, $mail['to_count']);
        $record = $this->records('notification')[0];
        $this->assertShape('notification', $record);
        $this->assertSame(Notification::class, $record['class']);
        $this->assertSame('mail', $record['channel']);
        $record = collect($this->records('log'))->firstWhere('message', 'text');
        $this->assertShape('log', $record);
        $this->assertSame('[REDACTED]', $record['context']['password']);
    }

    public function test_outgoing_responses_failures_and_ingest_exclusion(): void
    {
        $http = new Factory(app('events'));
        $http->fake(['*' => $http->response('', 201)]);
        $http->post('https://api.example.test/v1/pay');
        Event::dispatch(new ConnectionFailed(new Request(new PsrRequest('GET', 'https://offline.example.test')), new ConnectionException('offline')));
        foreach (['http://127.0.0.1/ingest/v1', 'http://127.0.0.1/another-path'] as $url) {
            Event::dispatch(new ResponseReceived(new Request(new PsrRequest('POST', $url)), new Response(new PsrResponse(202))));
        }
        $records = $this->records('http');
        $this->assertCount(2, $records);
        $this->assertShape('http', $records[0]);
        $this->assertSame('api.example.test', $records[0]['host']);
        $this->assertSame('POST', $records[0]['method']);
        $this->assertSame(201, $records[0]['status']);
        $this->assertSame(0, $records[1]['status']);
    }

    public function test_flush_uses_isolated_factory_and_produces_no_http_record(): void
    {
        $events = 0;
        Event::listen(ResponseReceived::class, function () use (&$events) {
            $events++;
        });
        app(Recorder::class)->record(['t' => 'log', 'message' => 'flush']);
        app(Recorder::class)->flush();
        app(Recorder::class)->flush();
        $this->transportHttp()->assertSentCount(1);
        $this->assertSame(0, $events);
        $this->assertSame([], $this->records('http'));
    }

    public function test_outgoing_urls_strip_credentials_and_redact_query_secrets(): void
    {
        config(['decisions.redact.fields' => [...config('decisions.redact.fields'), 'pin']]);
        app()->forgetInstance(Redactor::class);
        $query = 'api_key=private&KEY=private&signature=private&sig=private&access_token=private&PIN=private&%74oken=private&token[]=private&safe=visible&safe=second';
        $url = 'https://user:password@api.example.test:8443/pay?'.$query;
        $request = new Request(new PsrRequest('GET', $url));
        Event::dispatch(new ResponseReceived($request, new Response(new PsrResponse(200))));
        Event::dispatch(new ConnectionFailed($request, new ConnectionException('offline')));
        $expected = 'https://api.example.test:8443/pay?api_key=[redacted]&KEY=[redacted]&signature=[redacted]&sig=[redacted]&access_token=[redacted]&PIN=[redacted]&%74oken=[redacted]&token%5B%5D=[redacted]&safe=visible&safe=second';
        $records = $this->records('http');
        $this->assertCount(2, $records);
        $this->assertSame([$expected, $expected], array_column($records, 'url'));
        $this->assertSame(['api.example.test', 'api.example.test'], array_column($records, 'host'));
    }

    public function test_request_path_redacts_query_secrets_if_present(): void
    {
        $request = new class extends ServerRequest
        {
            public function getPathInfo(): string
            {
                return '/pay?api_key=private&access_token=private&safe=visible';
            }
        };
        Event::dispatch(new RequestHandled($request, response('ok')));
        $this->assertSame('/pay?api_key=[redacted]&access_token=[redacted]&safe=visible', $this->records('request')[0]['path']);
    }

    public function test_schedule_does_not_resolve_the_host_cache(): void
    {
        app(Schedule::class);
        app()->bind('cache', function () {
            $this->fail('Schedule monitoring resolved the host cache.');
        });
        $started = microtime(true);
        $this->tick('schedule:run');
        $this->tick('schedule:run');
        $this->assertLessThan(1, microtime(true) - $started);
        $this->assertCount(1, $this->records('schedule'));
    }

    public function test_schedule_filesystem_errors_do_not_escape(): void
    {
        $storage = app()->storagePath();
        $file = tempnam(sys_get_temp_dir(), 'decisions-storage-');
        app()->useStoragePath($file);
        try {
            $this->tick('schedule:run');
            $this->assertSame([], $this->records('schedule'));
        } finally {
            app()->useStoragePath($storage);
            unlink($file);
        }
    }

    public function test_schedule_does_not_wait_for_a_locked_stamp(): void
    {
        $stamp = storage_path('framework/decisions-schedule-'.config('decisions.deploy'));
        $this->scheduleStamps[] = $stamp;
        $handle = fopen($stamp, 'c+');
        flock($handle, LOCK_EX);
        try {
            $started = microtime(true);
            $this->tick('schedule:run');
            $this->assertLessThan(1, microtime(true) - $started);
            $this->assertSame([], $this->records('schedule'));
        } finally {
            fclose($handle);
        }
    }

    public function test_worker_manual_release_failure_and_delete_emit_one_record_each(): void
    {
        $handler = new class
        {
            public function fire(Job $job, array $data): void
            {
                match ($data['action']) {
                    'release' => $job->release(),
                    'fail' => $job->fail(new RuntimeException('manual failure')),
                    default => $job->delete(),
                };
            }
        };
        app()->instance('sensor-test-job', $handler);
        foreach (['release', 'fail', 'delete'] as $action) {
            $payload = json_encode(['job' => 'sensor-test-job@fire', 'data' => ['action' => $action]]);
            $job = new SyncJob(app(), $payload, 'sync', 'default');
            $worker = new Worker(app('queue'), app('events'), app(ExceptionHandler::class), fn () => false);
            $worker->process('sync', $job, new WorkerOptions);
        }
        $this->assertSame(['released', 'failed', 'processed'], array_column($this->records('job'), 'status'));
        $this->assertCount(1, $this->records('exception'));
        $this->assertSame('manual failure', $this->records('exception')[0]['message']);
    }

    public function test_nonzero_task_exit_emits_once_and_repeated_runs_are_retained(): void
    {
        $task = app(Schedule::class)->command('orders:fail');
        $task->exitCode = 1;
        for ($run = 0; $run < 2; $run++) {
            Event::dispatch(new ScheduledTaskFinished($task, 0.25));
            Event::dispatch(new ScheduledTaskFailed($task, new RuntimeException('nonzero exit'), 0.25));
        }
        $records = $this->records('task');
        $this->assertSame(['failed', 'failed'], array_column($records, 'status'));
        $this->assertSame([250.0, 250.0], array_column($records, 'ms'));
    }

    private function job(): Job
    {
        $job = $this->createMock(Job::class);
        $job->method('resolveName')->willReturn('App\\Jobs\\Sync');
        $job->method('getQueue')->willReturn('default');
        $job->method('attempts')->willReturn(2);

        return $job;
    }

    private function tick(string $command, int $exit = 0): void
    {
        if (in_array($command, ['schedule:run', 'schedule:work'], true)) {
            $this->scheduleStamps[] = storage_path('framework/decisions-schedule-'.rawurlencode((string) config('decisions.deploy')));
        }
        $input = new ArrayInput([]);
        $output = new NullOutput;
        Event::dispatch(new CommandStarting($command, $input, $output));
        Event::dispatch(new CommandFinished($command, $input, $output, $exit));
    }

    private function records(string $type): array
    {
        app(Recorder::class)->flush();

        return array_values(array_filter(array_merge([], ...array_column($this->batches(), 'records')), fn ($record) => $record['t'] === $type));
    }

    private function batches(): array
    {
        return $this->transportHttp()->recorded()->map(fn ($pair) => json_decode(gzdecode($pair[0]->body()), true, flags: JSON_THROW_ON_ERROR))->all();
    }

    private function assertShape(string $type, array $record, array $extra = []): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/batch-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $expected = collect($fixture['records'])->firstWhere('t', $type);
        unset($expected['user_name'], $expected['user_email']);
        $this->assertEqualsCanonicalizing(array_merge(array_keys($expected), $extra), array_keys($record));
        $this->assertIsFloat($record['at']);
        if ($type !== 'schedule') {
            $this->assertNotEmpty($record['trace']);
        }
    }
}
