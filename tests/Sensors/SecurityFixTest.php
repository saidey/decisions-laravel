<?php

namespace DecisionsAi\Laravel\Tests\Sensors;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Redactor;
use DecisionsAi\Laravel\Sensors\Exceptions;
use DecisionsAi\Laravel\Tests\TestCase;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class SecurityFixTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        foreach (['security', 'audit'] as $name) {
            $app['config']->set('logging.channels.'.$name, [
                'driver' => 'monolog', 'name' => $name, 'handler' => TestHandler::class,
            ]);
        }
        $app['config']->set('decisions.ignore.jobs', [SecurityIgnoredJob::class]);
        $app['config']->set('decisions.ignore.paths', ['/private/*']);
        if (str_contains($this->name(), 'buffer_one')) {
            $app['config']->set('decisions.buffer', 1);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 202)]);
    }

    public static function sensitiveMessages(): array
    {
        return [
            'email' => ['alice@example.test'],
            'bearer' => ['Bearer short-credential'],
            'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.signature'],
            'hex' => [str_repeat('a1', 16)],
            'base64' => ['c29tZS1zZWNyZXQtdmFsdWUtZm9yLXRlc3Rpbmc='],
            'password' => ['password="short secret"'],
            'token' => ['token=short-secret'],
            'json pair' => ['"api_key": "short-secret"'],
        ];
    }

    #[DataProvider('sensitiveMessages')]
    public function test_exception_and_log_messages_scrub_sensitive_values(string $secret): void
    {
        app(Exceptions::class)->handle(new RuntimeException('Failure: '.$secret));
        Log::channel('security')->error('Failure: '.$secret);
        $records = $this->records();
        $this->assertSame(['exception', 'log'], array_column($records, 't'));
        $this->assertSame(['Failure: [REDACTED]', 'Failure: [REDACTED]'], array_column($records, 'message'));
        $this->assertStringNotContainsString($secret, json_encode($records));
        $source = app(Redactor::class)->redact(['trace_frames' => [['source' => [1 => '// '.$secret]]]]);
        $this->assertSame('// [REDACTED]', $source['trace_frames'][0]['source'][1]);
    }

    public function test_query_exception_and_its_log_use_placeholders_and_driver_code(): void
    {
        $driver = new PDOException('Duplicate private-binding', 23000);
        $driver->errorInfo = ['23000', 1062, 'Duplicate private-binding'];
        $exception = new QueryException('mysql', 'insert into users (password) values (?)', ['private-binding'], $driver);
        app(Exceptions::class)->handle($exception);
        Log::channel('security')->error($exception->getMessage(), ['exception' => $exception]);
        $records = $this->records();
        $this->assertSame(array_fill(0, 2, 'insert into users (password) values (?) (driver code: 1062)'), array_column($records, 'message'));
        $this->assertStringNotContainsString('private-binding', json_encode($records));
        $this->assertStringContainsString('private-binding', Log::channel('security')->getLogger()->getHandlers()[0]->getRecords()[0]->message);
    }

    public function test_query_binding_opt_in_keeps_interpolated_message_but_still_scrubs_values(): void
    {
        config(['decisions.capture.bindings' => true]);
        $exception = new QueryException('mysql', 'select ?, ?', ['ordinary-value', 'alice@example.test'], new PDOException('driver failure', 7));
        app(Exceptions::class)->handle($exception);
        Log::channel('security')->error($exception->getMessage(), ['exception' => $exception]);
        foreach ($this->records() as $record) {
            $this->assertStringContainsString('ordinary-value', $record['message']);
            $this->assertStringContainsString('[REDACTED]', $record['message']);
            $this->assertStringNotContainsString('alice@example.test', $record['message']);
        }
    }

    public function test_stringified_query_exception_logs_do_not_export_interpolated_values(): void
    {
        $exception = new QueryException('mysql', 'select * from users where name = ?', ['private-binding'], new PDOException('private-binding', 7));
        Log::channel('security')->error($exception);
        Log::channel('security')->error($exception->getMessage());
        $records = $this->records();
        $this->assertSame(array_fill(0, 2, 'Database query failed [REDACTED]'), array_column($records, 'message'));
        $this->assertStringNotContainsString('private-binding', json_encode($records));
        $this->assertSame('update users set password = ? where token = :token (driver code: 7)', app(Redactor::class)->exceptionMessage(
            new QueryException('mysql', 'update users set password = ? where token = :token', [], new PDOException('driver failure', 7)),
        ));
    }

    public function test_password_reset_path_uses_route_template(): void
    {
        Route::get('/password/reset/{token}', fn () => 'ok');
        $this->get('/password/reset/short-secret')->assertOk();
        $record = $this->records()[0];
        $this->assertSame('/password/reset/{token}', $record['route']);
        $this->assertSame('/password/reset/{token}', $record['path']);
        $this->assertStringNotContainsString('short-secret', json_encode($record));
    }

    public function test_unmatched_paths_and_telegram_outgoing_urls_scrub_secret_segments(): void
    {
        $token = str_repeat('a', 24);
        $this->get('/unknown/'.$token)->assertNotFound();
        $http = new Factory(app('events'));
        $http->fake(['*' => $http->response('', 200)]);
        $http->get('https://api.telegram.org/bot123456:short-secret/sendMessage');
        $records = $this->records();
        $this->assertSame('/unknown/{redacted}', $records[0]['path']);
        $this->assertSame('https://api.telegram.org/{redacted}/sendMessage', $records[1]['url']);
        $redactor = app(Redactor::class);
        $this->assertSame('/reset/{redacted}', $redactor->redactUrl('/reset/'.str_repeat('%2561', 24)));
        $this->assertSame('https://api.telegram.org/{redacted}/getMe', $redactor->redactUrl('https://api.telegram.org/bot123%3Ashort/getMe'));
        $this->assertSame('https://example.test/orders/9?token=[redacted]&page=2', $redactor->redactUrl('https://user:secret@example.test/orders/9?token=secret&page=2'));
    }

    public function test_schedule_inventory_and_runs_share_argument_free_command_identities(): void
    {
        config(['decisions.deploy' => 'security-'.bin2hex(random_bytes(8))]);
        $schedule = app(Schedule::class);
        $tasks = [
            $schedule->command('orders:sync --token=private')->description('Artisan description'),
            $schedule->exec('/usr/bin/curl -H "Authorization: Bearer private" https://example.test'),
            $schedule->exec('"/path with spaces/curl" --password=private')->description('Fetch orders'),
            $schedule->call(fn () => null)->description('Refresh orders'),
            $schedule->call(fn () => null),
        ];
        $stamp = storage_path('framework/decisions-schedule-'.config('decisions.deploy'));
        try {
            Event::dispatch(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));
            foreach ($tasks as $index => $task) {
                Event::dispatch($index === 1
                    ? new ScheduledTaskFailed($task, new RuntimeException('failed'), 0.1)
                    : new ScheduledTaskFinished($task, 0.1));
            }
            $records = $this->records();
            $inventory = array_values(array_filter($records, fn ($record) => $record['t'] === 'schedule'))[0];
            $runs = array_values(array_filter($records, fn ($record) => $record['t'] === 'task'));
            $names = ['orders:sync', 'curl', 'Fetch orders', 'Refresh orders', 'Callback'];
            $this->assertSame($names, array_column($inventory['tasks'], 'command'));
            $this->assertSame($names, array_column($runs, 'command'));
            $this->assertStringNotContainsString('private', json_encode($records));
        } finally {
            if (is_file($stamp)) {
                unlink($stamp);
            }
        }
    }

    public function test_source_capture_rejects_symlinks_outside_base_and_scrubs_captured_lines(): void
    {
        $outside = tempnam(__DIR__, 'security-source-');
        $link = app_path('SecuritySourceLink.php');
        $inside = app_path('SecuritySource.php');
        if (! is_dir(dirname($link))) {
            mkdir(dirname($link), 0777, true);
        }
        file_put_contents($outside, "<?php\n// outside-content\nthrow new \\RuntimeException('outside');\n");
        file_put_contents($inside, "<?php\n// password=short-secret\n// alice@example.test\nthrow new \\RuntimeException('inside');\n");
        symlink($outside, $link);
        try {
            $sensor = app(Exceptions::class);
            $this->assertNull((new ReflectionMethod(Exceptions::class, 'source'))->invoke($sensor, $link, 3));
            foreach ([$link, $inside] as $file) {
                try {
                    require $file;
                } catch (RuntimeException $exception) {
                    $sensor->handle($exception);
                }
            }
            $records = $this->records();
            $this->assertArrayNotHasKey('source', $records[0]['trace_frames'][0]);
            $source = $records[1]['trace_frames'][0]['source'];
            $this->assertSame('// [REDACTED]', $source[2]);
            $this->assertSame('// [REDACTED]', $source[3]);
            $this->assertStringNotContainsString('outside-content', json_encode($records));
            $this->assertStringNotContainsString('short-secret', json_encode($records));
            $this->assertStringNotContainsString('alice@example.test', json_encode($records));
        } finally {
            unlink($link);
            unlink($inside);
            unlink($outside);
        }
    }

    public static function jobOutcomes(): array
    {
        return ['processed' => [false], 'failed' => [true]];
    }

    #[DataProvider('jobOutcomes')]
    public function test_ignored_sync_job_restores_request_trace(bool $fail): void
    {
        $this->assertSyncJobRestoresRequest($fail);
    }

    #[DataProvider('jobOutcomes')]
    public function test_ignored_sync_job_restores_request_trace_with_buffer_one(bool $fail): void
    {
        $this->assertSyncJobRestoresRequest($fail);
    }

    private function assertSyncJobRestoresRequest(bool $fail): void
    {
        Route::get('/sync', function () use ($fail) {
            Log::channel('security')->info('before');
            try {
                Queue::connection('sync')->push(new SecurityIgnoredJob($fail));
            } catch (RuntimeException) {
            }
            Log::channel('security')->info('after');
            app(Exceptions::class)->handle(new RuntimeException('request exception'));

            return 'ok';
        });
        $this->get('/sync')->assertOk();
        $records = $this->records();
        $this->assertSame(['log', 'log', 'exception', 'request'], array_column($records, 't'));
        $this->assertSame(['before', 'after'], array_column(array_slice($records, 0, 2), 'message'));
        $this->assertCount(1, array_unique(array_column($records, 'trace')));
        $this->assertSame(0, app(Recorder::class)->droppedRecords());
    }

    public function test_ignored_request_also_suppresses_public_sync_jobs(): void
    {
        Route::get('/private/sync', function () {
            Queue::connection('sync')->push(new SecurityPublicJob);

            return 'ok';
        });
        $this->get('/private/sync')->assertOk();
        $this->assertSame([], $this->records());
    }

    public function test_on_demand_and_nested_log_stacks_record_each_call_once(): void
    {
        $stack = Log::stack(['security', 'audit'], 'dynamic');
        $stack->info('same message');
        $stack->info('same message');
        Log::stack([$stack, Log::channel('security')], 'nested')->error('nested message');
        Log::channel('audit')->warning('ordinary message');
        $records = $this->records();
        $this->assertSame(['same message', 'same message', 'nested message', 'ordinary message'], array_column($records, 'message'));
        $this->assertSame(['dynamic', 'dynamic', 'nested', 'audit'], array_column($records, 'channel'));
        $this->assertCount(4, Log::channel('security')->getLogger()->getHandlers()[0]->getRecords());
        $this->assertCount(4, Log::channel('audit')->getLogger()->getHandlers()[0]->getRecords());
    }

    private function records(): array
    {
        app(Recorder::class)->flush();

        return $this->transportHttp()->recorded()->flatMap(fn ($pair) => json_decode(gzdecode($pair[0]->body()), true, flags: JSON_THROW_ON_ERROR)['records'])->all();
    }
}

class SecurityIgnoredJob implements ShouldQueue
{
    public function __construct(private bool $fail) {}

    public function handle(): void
    {
        Log::channel('security')->error('private job log');
        app(Recorder::class)->record(['t' => 'query', 'sql' => 'private job query']);
        app(Exceptions::class)->handle(new RuntimeException('private job exception'));
        if ($this->fail) {
            throw new RuntimeException('private job failure');
        }
    }
}

class SecurityPublicJob implements ShouldQueue
{
    public function handle(): void
    {
        Log::channel('security')->info('public job log');
    }
}
