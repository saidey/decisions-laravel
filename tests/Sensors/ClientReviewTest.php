<?php

namespace DecisionsAi\Laravel\Tests\Sensors;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Sensors\Exceptions;
use DecisionsAi\Laravel\Tests\TestCase;
use DomainException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use LogicException;
use Monolog\Handler\TestHandler;
use ReflectionProperty;
use RuntimeException;
use SplFileObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

class ClientReviewTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        foreach (['payments', 'audit'] as $name) {
            $app['config']->set('logging.channels.'.$name, [
                'driver' => 'monolog', 'name' => $name, 'handler' => TestHandler::class,
            ]);
        }
        $app['config']->set('logging.channels.review-stack', [
            'driver' => 'stack', 'name' => 'review-stack', 'channels' => ['payments', 'audit'],
        ]);
        if (str_contains($this->name(), 'ignored_')) {
            $app['config']->set('decisions.buffer', 1);
            $app['config']->set('decisions.ignore', [
                'paths' => ['/private/*'], 'commands' => ['private:*'],
                'jobs' => ['App\\Jobs\\Private*'], 'exceptions' => [LogicException::class, RuntimeException::class],
            ]);
        }
        if ($this->name() === 'test_sampling_keeps_exceptions_at_their_own_rate') {
            $app['config']->set('decisions.sample.log', 0.0);
        }
        if ($this->name() === 'test_zero_exception_sampling_preserves_logs') {
            $app['config']->set('decisions.sample.exception', 0.0);
        }
        if ($this->name() === 'test_fractional_sampling_is_per_record') {
            $app['config']->set('decisions.sample.log', 0.5);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 202)]);
    }

    public function test_sampling_keeps_exceptions_at_their_own_rate(): void
    {
        Log::channel('payments')->error('sampled out');
        app(Exceptions::class)->handle(new RuntimeException('retained'));
        $this->assertSame(['exception'], array_column($this->records(), 't'));
        $this->assertSame(0, app(Recorder::class)->droppedRecords());
    }

    public function test_zero_exception_sampling_preserves_logs(): void
    {
        app(Exceptions::class)->handle(new RuntimeException('sampled out'));
        Log::channel('payments')->info('retained');
        $this->assertSame(['log'], array_column($this->records(), 't'));
    }

    public function test_fractional_sampling_is_per_record(): void
    {
        for ($index = 0; $index < 200; $index++) {
            Log::channel('payments')->info('record '.$index);
        }
        app(Exceptions::class)->handle(new RuntimeException('always retained'));
        $records = $this->records();
        $logs = array_filter($records, fn ($record) => $record['t'] === 'log');
        $this->assertGreaterThan(0, count($logs));
        $this->assertLessThan(200, count($logs));
        $this->assertCount(1, array_unique(array_column($records, 'trace')));
        $this->assertSame('exception', $records[array_key_last($records)]['t']);
    }

    public function test_ignored_request_drops_children_before_any_buffer_flush_and_next_request_resets(): void
    {
        Route::get('/private/health', function () {
            $this->children();

            return 'private';
        });
        Route::get('/public', function () {
            $this->children();

            return 'public';
        });
        $this->get('/private/health')->assertOk();
        $this->assertSame([], $this->records());
        $this->transportHttp()->assertNothingSent();
        $this->get('/public')->assertOk();
        $this->assertSame(['log', 'query', 'exception', 'request'], array_column($this->records(), 't'));
    }

    public function test_ignored_command_drops_children_and_next_command_resets(): void
    {
        foreach (['private:sync', 'public:sync'] as $name) {
            $input = new ArrayInput([]);
            $output = new NullOutput;
            Event::dispatch(new CommandStarting($name, $input, $output));
            $this->children();
            Event::dispatch(new CommandFinished($name, $input, $output, 0));
            if ($name === 'private:sync') {
                $this->transportHttp()->assertNothingSent();
            }
        }
        $this->assertSame(['log', 'query', 'exception', 'command'], array_column($this->records(), 't'));
    }

    public function test_ignored_job_drops_children_and_failure_and_next_job_resets(): void
    {
        $job = $this->job('App\\Jobs\\PrivateSync');
        Event::dispatch(new JobProcessing('sync', $job));
        $this->children();
        Event::dispatch(new JobFailed('sync', $job, new \Exception('private failure')));
        $this->transportHttp()->assertNothingSent();
        $job = $this->job('App\\Jobs\\PublicSync');
        Event::dispatch(new JobProcessing('sync', $job));
        $this->children();
        Event::dispatch(new JobProcessed('sync', $job));
        $this->assertSame(['log', 'query', 'exception', 'job'], array_column($this->records(), 't'));
    }

    public function test_ignored_exceptions_match_exact_and_parent_classes(): void
    {
        foreach ([new RuntimeException('exact'), new DomainException('parent'), new \Exception('retained')] as $exception) {
            app(ExceptionHandler::class)->report($exception);
        }
        $records = array_values(array_filter($this->records(), fn ($record) => $record['t'] === 'exception'));
        $this->assertCount(1, $records);
        $this->assertSame('retained', $records[0]['message']);
        $this->assertSame(0, app(Recorder::class)->droppedRecords());
    }

    public function test_two_named_log_channels_and_stack_keep_real_channel_without_duplicates(): void
    {
        Log::channel('payments')->info('payment', ['password' => 'secret']);
        Log::channel('audit')->warning('audit');
        Log::channel('review-stack')->error('stacked');
        $records = $this->records();
        $this->assertSame(['payments', 'audit', 'review-stack'], array_column($records, 'channel'));
        $this->assertSame(['info', 'warning', 'error'], array_column($records, 'level'));
        $this->assertSame('[REDACTED]', $records[0]['context']['password']);
        $this->assertCount(2, Log::channel('payments')->getLogger()->getHandlers()[0]->getRecords());
        $this->assertCount(2, Log::channel('audit')->getLogger()->getHandlers()[0]->getRecords());
    }

    public function test_connection_failed_sensor_keeps_zero_status_on_wire(): void
    {
        $http = new Factory(app('events'));
        $http->fake(fn ($request) => throw new ConnectException('offline', $request->toPsrRequest()));
        try {
            $http->get('https://offline.example.test');
            $this->fail('Expected a connection failure.');
        } catch (ConnectionException) {
        }
        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertSame('http', $records[0]['t']);
        $this->assertSame(0, $records[0]['status']);
    }

    public function test_object_log_context_is_redacted_on_the_wire(): void
    {
        $object = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['password' => 'review-secret', 'body' => 'private', 'user_email' => 'private@example.test'];
            }
        };
        Log::channel('payments')->info('object context', [
            'credentials' => $object, 'plain' => (object) ['password' => 'review-secret'],
        ]);
        $record = $this->records()[0];
        $this->assertSame(['password' => '[REDACTED]'], $record['context']['credentials']);
        $this->assertSame(['class' => 'stdClass'], $record['context']['plain']);
        $this->assertStringNotContainsString('review-secret', json_encode($record));
        $this->assertStringNotContainsString('private', json_encode($record));
    }

    public function test_background_launch_is_silent_and_finish_command_records_nonzero_exit(): void
    {
        $task = app(Schedule::class)->exec('exit 7')->runInBackground();
        Event::dispatch(new ScheduledTaskFinished($task, 0.01));
        $this->assertSame([], $this->records());
        $process = new Process([PHP_BINARY, '-r', 'exit(7);']);
        $this->assertSame(7, $process->run());
        $this->assertSame(0, Artisan::call('schedule:finish', ['id' => $task->mutexName(), 'code' => $process->getExitCode()]));
        $records = array_values(array_filter($this->records(), fn ($record) => $record['t'] === 'task'));
        $this->assertCount(1, $records);
        $this->assertSame(7, $task->exitCode);
        $this->assertSame('failed', $records[0]['status']);
        $this->assertSame('exit', $records[0]['command']);
        $this->assertSame(0.0, $records[0]['ms']);
    }

    public function test_source_file_handles_are_cached_per_trace_and_large_files_are_skipped(): void
    {
        $small = app_path('ReviewSource.php');
        $large = app_path('ReviewLargeSource.php');
        if (! is_dir(dirname($small))) {
            mkdir(dirname($small), 0777, true);
        }
        file_put_contents($small, "<?php\nreturn function () {\nthrow new \\RuntimeException('source');\n};\n".str_repeat("// source\n", 10));
        file_put_contents($large, "<?php\nthrow new \\RuntimeException('large');\n".str_repeat(' ', 2 * 1024 * 1024));
        try {
            $throw = require $small;
            $sensor = app(Exceptions::class);
            $cache = new ReflectionProperty(Exceptions::class, 'sources');
            $handles = [];
            for ($run = 0; $run < 3; $run++) {
                if ($run === 2) {
                    app(Recorder::class)->startTrace();
                }
                try {
                    $throw();
                } catch (RuntimeException $exception) {
                    $sensor->handle($exception);
                }
                $handles[] = $cache->getValue($sensor)[$small];
            }
            $this->assertInstanceOf(SplFileObject::class, $handles[0]);
            $this->assertSame($handles[0], $handles[1]);
            $this->assertNotSame($handles[0], $handles[2]);
            try {
                require $large;
            } catch (RuntimeException $exception) {
                $sensor->handle($exception);
            }
            $records = $this->records();
            $this->assertSame(range(1, 8), array_keys($records[0]['trace_frames'][0]['source']));
            $this->assertArrayNotHasKey('source', $records[3]['trace_frames'][0]);
            $this->assertNull($cache->getValue($sensor)[$large]);
        } finally {
            unlink($small);
            unlink($large);
        }
    }

    private function children(): void
    {
        Log::channel('payments')->info('child');
        Event::dispatch(new QueryExecuted('select 1', [], 1, new Connection(null, '', '', ['name' => 'mysql'])));
        app(Exceptions::class)->handle(new \Exception('child exception'));
    }

    private function job(string $name): Job
    {
        $job = $this->createMock(Job::class);
        $job->method('resolveName')->willReturn($name);
        $job->method('getQueue')->willReturn('default');
        $job->method('attempts')->willReturn(1);

        return $job;
    }

    private function records(): array
    {
        app(Recorder::class)->flush();

        return $this->transportHttp()->recorded()->flatMap(fn ($pair) => json_decode(gzdecode($pair[0]->body()), true, flags: JSON_THROW_ON_ERROR)['records'])->all();
    }
}
