<?php

namespace DecisionsAi\Laravel\Tests;

use DecisionsAi\Laravel\DecisionsServiceProvider;
use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Redactor;
use DecisionsAi\Laravel\Transport;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class RecorderTest extends TestCase
{
    public function test_batch_matches_fixture_shape(): void
    {
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 202)]);
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/batch-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $config = config('decisions');
        $config['capture']['source'] = true;
        $config['user_details'] = true;
        $recorder = new Recorder(app(Transport::class), new Redactor($config), $config);

        foreach ($fixture['records'] as $record) {
            $recorder->record($record);
        }
        $recorder->flush();

        $batch = $this->batches()[0];
        $this->assertSame(array_keys($fixture), array_keys($batch));
        $this->assertSame(1, $batch['v']);
        $this->assertSame('production', $batch['environment']);
        $this->assertSame('a1b2c3', $batch['deploy']);
        $this->assertNotEmpty($batch['server']);
        foreach ($fixture['records'] as $index => $record) {
            $expected = array_keys($record);
            $this->assertEqualsCanonicalizing($expected, array_keys($batch['records'][$index]));
            $this->assertIsFloat($batch['records'][$index]['at']);
            if ($record['t'] !== 'schedule') {
                $this->assertSame($batch['records'][0]['trace'], $batch['records'][$index]['trace']);
            }
        }
    }

    public function test_buffer_flushes_at_one_thousand_and_termination_sends_the_next_record(): void
    {
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 202)]);
        $recorder = app(Recorder::class);

        for ($index = 0; $index < 1001; $index++) {
            $recorder->record(['t' => 'log', 'message' => (string) $index]);
        }
        $this->transportHttp()->assertSentCount(1);
        $this->app->terminate();

        $batches = $this->batches();
        $this->transportHttp()->assertSentCount(2);
        $this->assertCount(1000, $batches[0]['records']);
        $this->assertCount(1, $batches[1]['records']);
        $this->assertSame('1000', $batches[1]['records'][0]['message']);
        $this->assertSame(0, $recorder->droppedRecords());
        $recorder->flush();
        $this->transportHttp()->assertSentCount(2);
    }

    public function test_payloads_and_user_details_are_private_by_default(): void
    {
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 202)]);
        $recorder = app(Recorder::class);
        $recorder->record([
            't' => 'request', 'body' => ['password' => 'secret'], 'payload' => ['email' => 'private'],
            'user' => '42', 'user_name' => 'Private', 'user_email' => 'private@example.test',
            'headers' => ['Authorization' => 'Bearer secret'],
        ]);
        $recorder->flush();

        $record = $this->batches()[0]['records'][0];
        $this->assertArrayNotHasKey('body', $record);
        $this->assertArrayNotHasKey('payload', $record);
        $this->assertArrayNotHasKey('user_name', $record);
        $this->assertArrayNotHasKey('user_email', $record);
        $this->assertSame('42', $record['user']);
        $this->assertSame('[REDACTED]', $record['headers']['Authorization']);
    }

    public function test_disabled_or_missing_token_does_not_send(): void
    {
        $this->transportHttp()->fake();
        foreach ([['enabled' => false, 'token' => 'test'], ['enabled' => true, 'token' => null]] as $overrides) {
            $config = array_replace(config('decisions'), $overrides);
            $recorder = new Recorder(new Transport($config), new Redactor($config), $config);
            $recorder->record(['t' => 'log']);
            $recorder->flush();
        }
        $this->transportHttp()->assertNothingSent();
    }

    public function test_failed_batches_are_counted_and_not_retried(): void
    {
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 500)]);
        $recorder = app(Recorder::class);
        $recorder->record(['t' => 'log']);
        $recorder->flush();
        $recorder->flush();

        $this->transportHttp()->assertSentCount(1);
        $this->assertSame(1, $recorder->droppedRecords());
    }

    public function test_start_trace_flushes_pending_records_and_resets_identity(): void
    {
        $this->transportHttp()->fake();
        $recorder = app(Recorder::class);
        $first = $recorder->startTrace();
        $recorder->record(['t' => 'log', 'trace' => 'stale']);
        $second = $recorder->startTrace();
        $recorder->record(['t' => 'log']);
        $recorder->flush();

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $first);
        $this->assertSame($first, $this->batches()[0]['records'][0]['trace']);
        $this->assertSame($second, $this->batches()[1]['records'][0]['trace']);
    }

    public function test_provider_registers_singleton_config_and_publish_group(): void
    {
        $this->assertSame(app(Recorder::class), app(Recorder::class));
        $this->assertSame(1000, config('decisions.buffer'));
        $this->assertSame(1.0, config('decisions.sample.exception'));
        $this->assertFalse(config('decisions.capture.payloads'));
        $paths = DecisionsServiceProvider::pathsToPublish(DecisionsServiceProvider::class, 'decisions-config');
        $this->assertContains(config_path('decisions.php'), $paths);
    }

    public function test_command_and_queue_lifecycle_hooks_flush_and_start_traces(): void
    {
        $this->transportHttp()->fake();
        $recorder = app(Recorder::class);
        $input = new ArrayInput([]);
        $output = new NullOutput;
        Event::dispatch(new CommandStarting('orders:sync', $input, $output));
        $recorder->record(['t' => 'command']);
        Event::dispatch(new CommandFinished('orders:sync', $input, $output, 0));
        $job = $this->createMock(Job::class);
        Event::dispatch(new JobProcessing('sync', $job));
        $recorder->record(['t' => 'job']);
        Event::dispatch(new JobProcessed('sync', $job));
        Event::dispatch(new JobProcessing('sync', $job));
        $recorder->record(['t' => 'job']);
        Event::dispatch(new JobFailed('sync', $job, new RuntimeException('failed')));

        $this->transportHttp()->assertSentCount(3);
        $traces = array_column(array_map(fn ($batch) => $batch['records'][0], $this->batches()), 'trace');
        $this->assertCount(3, array_unique($traces));
    }

    public function test_octane_lifecycle_resets_and_flushes_each_request(): void
    {
        $this->transportHttp()->fake();
        $recorder = app(Recorder::class);
        Event::dispatch('Laravel\\Octane\\Events\\RequestReceived', [new \stdClass]);
        $recorder->record(['t' => 'request']);
        Event::dispatch('Laravel\\Octane\\Events\\RequestTerminated', [new \stdClass]);
        Event::dispatch('Laravel\\Octane\\Events\\RequestReceived', [new \stdClass]);
        $recorder->record(['t' => 'request']);
        Event::dispatch('Laravel\\Octane\\Events\\RequestTerminated', [new \stdClass]);

        $this->transportHttp()->assertSentCount(2);
        $batches = $this->batches();
        $this->assertNotSame($batches[0]['records'][0]['trace'], $batches[1]['records'][0]['trace']);
        $this->assertCount(1, $batches[1]['records']);
    }

    public function test_public_entry_points_swallow_throwables(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willThrowException(new RuntimeException('transport'));
        $recorder = new Recorder($transport, new Redactor, config('decisions'));
        $recorder->record(['t' => 'log']);
        $recorder->flush();
        $this->assertIsString($recorder->startTrace());

        $redactor = $this->createMock(Redactor::class);
        $redactor->method('redact')->willThrowException(new RuntimeException('redactor'));
        $recorder = new Recorder($transport, $redactor, config('decisions'));
        $recorder->record(['t' => 'log']);
        $this->assertSame(1, $recorder->droppedRecords());
    }

    private function batches(): array
    {
        return $this->transportHttp()->recorded()->map(fn ($pair) => json_decode(gzdecode($pair[0]->body()), true, flags: JSON_THROW_ON_ERROR))->all();
    }
}
