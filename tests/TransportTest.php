<?php

namespace DecisionsAi\Laravel\Tests;

use DecisionsAi\Laravel\Transport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TransportTest extends TestCase
{
    public function test_sends_gzipped_json_to_versioned_endpoint_with_bearer_token(): void
    {
        Http::fake(['*' => Http::response('', 202)]);
        $transport = app(Transport::class);
        $batch = ['v' => 1, 'records' => [['t' => 'log', 'at' => 1762761600.123]]];
        $transport->send($batch);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1/ingest/v1'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Content-Encoding', 'gzip')
            && $request->hasHeader('Content-Type', 'application/json')
            && json_decode(gzdecode($request->body()), true) === $batch);
        $this->assertSame(0, $transport->droppedRecords());
    }

    public function test_closed_port_returns_without_throwing_within_one_point_two_seconds(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $http = new Factory;
        $transport = new Transport(array_replace(config('decisions'), ['url' => 'http://'.$address]), $http);

        $started = microtime(true);
        $transport->send(['records' => [['t' => 'log']]]);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(1.2, $elapsed);
        $this->assertSame(1, $transport->droppedRecords());
    }

    public function test_socket_that_accepts_but_never_answers_times_out_within_one_point_two_seconds(): void
    {
        $server = <<<'PHP'
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { exit(1); }
fwrite(STDOUT, stream_socket_get_name($socket, false)."\n");
fflush(STDOUT);
$connection = stream_socket_accept($socket, 10);
if ($connection !== false) { sleep(30); fclose($connection); }
fclose($socket);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $server], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 5);
            $address = trim(fgets($pipes[1]));
            $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $address);
            $transport = new Transport(array_replace(config('decisions'), ['url' => 'http://'.$address]), new Factory);
            $started = microtime(true);
            $transport->send(['records' => [['t' => 'log']]]);
            $elapsed = microtime(true) - $started;

            $this->assertGreaterThanOrEqual(0.9, $elapsed);
            $this->assertLessThan(1.2, $elapsed);
            $this->assertSame(1, $transport->droppedRecords());
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }

    public function test_server_error_is_dropped_without_throwing_or_retrying(): void
    {
        Http::fake(['*' => Http::response('failure', 500)]);
        $transport = app(Transport::class);
        $transport->send(['records' => [['t' => 'log'], ['t' => 'exception']]]);

        Http::assertSentCount(1);
        $this->assertSame(2, $transport->droppedRecords());
    }

    public function test_encoding_errors_and_client_throwables_are_contained(): void
    {
        Http::fake(fn () => throw new RuntimeException('client failed'));
        $transport = app(Transport::class);
        $transport->send(['records' => [['t' => 'log', 'value' => INF]]]);
        Http::assertNothingSent();
        $transport->send(['records' => [['t' => 'log']]]);
        $this->assertSame(2, $transport->droppedRecords());
    }
}
