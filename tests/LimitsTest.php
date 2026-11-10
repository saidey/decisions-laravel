<?php

namespace DecisionsAi\Laravel\Tests;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Transport;
use Illuminate\Support\Facades\Log;

class LimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 202)]);
    }

    public function test_strings_and_log_messages_have_byte_limits_without_breaking_utf8(): void
    {
        Log::info(str_repeat('word ', 4000), ['safe' => str_repeat('é', 10000)]);
        app(Recorder::class)->record(['t' => 'query', 'sql' => str_repeat('x', 10000)]);
        app(Recorder::class)->flush();
        $records = $this->batches()[0]['records'];
        $this->assertSame(16384, strlen($records[0]['message']));
        $this->assertSame(8192, strlen($records[0]['context']['safe']));
        $this->assertSame(8192, strlen($records[1]['sql']));
        $this->assertSame(0, app(Recorder::class)->droppedRecords());
    }

    public function test_context_json_is_bounded_after_redaction_and_encoding(): void
    {
        $context = array_fill(0, 4, str_repeat('x', 8192));
        $context[3] = '';
        $context[3] = str_repeat('x', 32768 - strlen(json_encode($context)));
        Log::info('at limit', $context);
        $context[3] .= 'x';
        Log::info('over limit', $context);
        app(Recorder::class)->flush();
        $records = $this->batches()[0]['records'];
        $this->assertSame(32768, strlen(json_encode($records[0]['context'])));
        $this->assertSame(['truncated' => true], $records[1]['context']);
        $this->assertSame(0, app(Recorder::class)->droppedRecords());
    }

    public function test_encoded_record_at_64_kb_is_kept_and_one_byte_over_is_counted_as_dropped(): void
    {
        $record = ['t' => 'query', 'at' => 1.0, 'trace' => str_repeat('x', 36), 'fields' => array_fill(0, 7, str_repeat('x', 8192))];
        $record['fields'][] = '';
        $record['fields'][7] = str_repeat('x', 65536 - strlen(json_encode($record, Transport::JSON_FLAGS)));
        app(Recorder::class)->record($record);
        $record['fields'][7] .= 'x';
        app(Recorder::class)->record($record);
        app(Recorder::class)->flush();
        $records = $this->batches()[0]['records'];
        $this->assertCount(1, $records);
        $this->assertSame(65536, strlen(json_encode($records[0], Transport::JSON_FLAGS)));
        $this->assertSame(1, app(Recorder::class)->droppedRecords());
    }

    public function test_batch_flushes_before_encoded_json_exceeds_900_kb(): void
    {
        $record = ['t' => 'query', 'fields' => array_fill(0, 7, str_repeat('x', 8192))];
        for ($index = 0; $index < 32; $index++) {
            app(Recorder::class)->record($record);
        }
        app(Recorder::class)->flush();
        $batches = $this->batches();
        $this->assertCount(2, $batches);
        $this->assertSame(32, array_sum(array_map(fn ($batch) => count($batch['records']), $batches)));
        foreach ($this->transportHttp()->recorded() as [$request]) {
            $this->assertLessThanOrEqual(Transport::MAX_BATCH_BYTES, strlen(gzdecode($request->body())));
            $this->assertLessThan(1024 * 1024, strlen($request->body()));
        }
        $next = $batches[0];
        $next['records'][] = $batches[1]['records'][0];
        $this->assertGreaterThan(Transport::MAX_BATCH_BYTES, strlen(json_encode($next, Transport::JSON_FLAGS)));
        $this->assertSame(0, app(Recorder::class)->droppedRecords());
    }

    public function test_json_escaping_is_included_in_record_budget(): void
    {
        app(Recorder::class)->record(['t' => 'query', 'fields' => array_fill(0, 5, str_repeat('é', 4096))]);
        app(Recorder::class)->flush();
        $this->transportHttp()->assertNothingSent();
        $this->assertSame(1, app(Recorder::class)->droppedRecords());
    }

    public function test_very_wide_records_are_dropped_before_building_unbounded_normalized_trees(): void
    {
        app(Recorder::class)->record(['t' => 'query', 'fields' => array_fill(0, 10000, str_repeat('x', 8192))]);
        app(Recorder::class)->flush();
        $this->transportHttp()->assertNothingSent();
        $this->assertSame(1, app(Recorder::class)->droppedRecords());
    }

    public function test_transport_rejects_oversized_encoded_batches_without_sending_and_counts_records(): void
    {
        app(Transport::class)->send(['records' => [
            ['t' => 'log', 'message' => str_repeat('x', 460800)],
            ['t' => 'log', 'message' => str_repeat('x', 460800)],
        ]]);
        $this->transportHttp()->assertNothingSent();
        $this->assertSame(2, app(Transport::class)->droppedRecords());
    }

    private function batches(): array
    {
        return $this->transportHttp()->recorded()->map(fn ($pair) => json_decode(gzdecode($pair[0]->body()), true, flags: JSON_THROW_ON_ERROR))->all();
    }
}
