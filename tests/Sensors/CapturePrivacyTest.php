<?php

namespace DecisionsAi\Laravel\Tests\Sensors;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Redactor;
use DecisionsAi\Laravel\RequestLifecycle;
use DecisionsAi\Laravel\Sensors\Exceptions;
use DecisionsAi\Laravel\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

class CapturePrivacyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('decisions.capture.payloads', true);
        $app['config']->set('decisions.capture.bindings', true);
        $app['config']->set('logging.channels.capture', [
            'driver' => 'monolog', 'name' => 'capture', 'handler' => TestHandler::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 202)]);
    }

    public static function identityFields(): array
    {
        return array_map(fn ($field) => [$field], [
            'national_id', 'nationalid', 'passport', 'passport_number', 'passport_no',
            'id_number', 'id_no', 'id_card', 'identity_number', 'nic', 'tin', 'tax_id',
            'driving_licence', 'driving_license', 'licence_number', 'license_number',
            'customerNationalId', 'PASSPORT-NUMBER', 'employee_tax_id_copy',
        ]);
    }

    #[DataProvider('identityFields')]
    public function test_identity_fields_are_masked_at_any_payload_depth(string $field): void
    {
        $record = app(Redactor::class)->redact(['payload' => ['nested' => [$field => 'short-value']]]);
        $this->assertSame('[REDACTED]', $record['payload']['nested'][$field]);
        config(['decisions.capture.bindings_strings' => 'scrubbed']);
        $this->assertSame(['[redacted]'], app(Redactor::class)->queryBindings('select * from people where "'.$field.'" = ?', ['short-value']));
    }

    public function test_identity_tokens_are_scrubbed_without_masking_foreign_keys_or_ordinary_ids(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $input = ['id' => 42, 'user_id' => 7, 'team_id' => '12', 'reference' => 'INV-123456', 'uuid' => $uuid,
            'word' => 'XA123456Y PA1234567Z', 'note' => 'A123456 / P1234567 / a654321 / z7654321'];
        $record = app(Redactor::class)->redact(['payload' => $input]);
        $input['note'] = '[REDACTED] / [REDACTED] / [REDACTED] / [REDACTED]';
        $this->assertSame($input, $record['payload']);
        $this->assertSame($uuid.' INV-123456', app(Redactor::class)->scrubValue($uuid.' INV-123456'));
    }

    public function test_default_binding_mode_masks_strings_and_keeps_numeric_scalar_bindings(): void
    {
        $this->assertSame('masked', config('decisions.capture.bindings_strings'));
        $bindings = ['ordinary-value', 'A123456', '123', 12345678, 1.5, true, false, null];
        $this->assertSame(['?', '?', '123', 12345678, 1.5, true, false, null], app(Redactor::class)->queryBindings('select ?, ?, ?, ?, ?, ?, ?, ?', $bindings));
        $this->recordQuery('select ?, ?, ?, ?', ['ordinary-value', 42, true, null]);
        $this->assertSame("select '?', 42, 1, null", $this->records('query')[0]['sql']);
    }

    public static function columnBindings(): array
    {
        return [
            'comparison' => ['select * from people where national_id = ? and user_id = ?', ['short-id', 42], ['[redacted]', 42]],
            'qualified quoted columns' => ['select * from people where "people"."passport_no" = ? and `team_id` = ?', ['short-id', 'team'], ['[redacted]', 'team']],
            'in list' => ['select * from people where tax_id in (?, ?) and id = ?', ['first', 'second', 7], ['[redacted]', '[redacted]', 7]],
            'insert' => ['insert into people (national_id, name) values (?, ?)', ['short-id', 'ordinary'], ['[redacted]', 'ordinary']],
            'multiple rows and literal' => ["insert into people (name, passport) values ('literal ?', ?), (?, ?)", ['first', 'ordinary', 'second'], ['[redacted]', 'ordinary', '[redacted]']],
            'insert expressions' => ['insert into people (name, passport) values (coalesce(?, ?), ?)', ['first', 'second', 'short-id'], ['first', 'second', '[redacted]']],
            'update' => ['update people set name = ?, passport_number = ? where team_id = ?', ['ordinary', 'short-id', 7], ['ordinary', '[redacted]', 7]],
            'credentials and custom fields' => ['update people set client_secret = ?, private_value = ?', ['first', 'second'], ['[redacted]', '[redacted]']],
            'literal and comment placeholders' => ["select '?' from people /* ? */ where passport = ? -- ?\n and name = ?", ['short-id', 'ordinary'], ['[redacted]', 'ordinary']],
            'unknown columns scrub values' => ['select ?, ?, ?, ?, ?', ['ordinary', 'A123456', 'P1234567', 'alice@example.test', 'INV-123456'], ['ordinary', '[REDACTED]', '[REDACTED]', '[REDACTED]', 'INV-123456']],
        ];
    }

    #[DataProvider('columnBindings')]
    public function test_scrubbed_binding_mode_uses_column_context(string $sql, array $bindings, array $expected): void
    {
        config(['decisions.capture.bindings_strings' => 'scrubbed']);
        $config = config('decisions');
        $config['redact']['fields'][] = 'private_value';
        $this->assertSame($expected, (new Redactor($config))->queryBindings($sql, $bindings));
    }

    public function test_query_sensor_masks_sensitive_columns_and_keeps_ordinary_values_in_scrubbed_mode(): void
    {
        config(['decisions.capture.bindings_strings' => 'scrubbed']);
        $this->recordQuery('update people set passport = ?, name = ? where user_id = ?', ['short-id', 'ordinary', 42]);
        $this->assertSame("update people set passport = '[redacted]', name = 'ordinary' where user_id = 42", $this->records('query')[0]['sql']);
    }

    public function test_query_exceptions_and_logs_share_binding_rules_and_omit_driver_values(): void
    {
        foreach (['masked', 'scrubbed'] as $mode) {
            config(['decisions.capture.bindings_strings' => $mode]);
            $exception = new QueryException('mysql', 'update people set passport = ?, name = ? where user_id = ?', ['short-id', 'ordinary', 42], new PDOException('Duplicate short-id and ordinary', 7));
            app(Exceptions::class)->handle($exception);
            Log::channel('capture')->error($exception->getMessage(), ['exception' => $exception]);
            Log::channel('capture')->error($exception->getMessage());
        }
        $exceptions = $this->records('exception');
        $logs = $this->records('log');
        foreach ($exceptions as $index => $record) {
            $this->assertSame($record['message'], $logs[$index * 2]['message']);
            $this->assertStringNotContainsString('short-id', $record['message']);
            $this->assertStringNotContainsString('Duplicate', $record['message']);
            $this->assertStringContainsString('42', $record['message']);
            $this->assertSame('Database query failed [REDACTED]', $logs[$index * 2 + 1]['message']);
        }
        $this->assertStringNotContainsString('ordinary', $exceptions[0]['message']);
        $this->assertStringContainsString('[redacted]', $exceptions[1]['message']);
        $this->assertStringContainsString('ordinary', $exceptions[1]['message']);
    }

    public function test_payload_exclusions_preserve_request_events_and_unmatched_payloads(): void
    {
        config(['decisions.capture.payload_except' => ['bank/*', 'hr/*', 'api/identity/*']]);
        foreach (['/bank/transfer', '/hr/staff/42', '/api/identity/check', '/orders', '/banking/orders'] as $path) {
            Event::dispatch(new RequestHandled(Request::create($path, 'POST', ['note' => 'ordinary']), response('ok')));
            app(RequestLifecycle::class)->finish(false);
        }
        $records = $this->records('request');
        $this->assertCount(5, $records);
        foreach (array_slice($records, 0, 3) as $record) {
            $this->assertArrayNotHasKey('payload', $record);
            $this->assertSame(200, $record['status']);
        }
        $this->assertSame(['note' => 'ordinary'], $records[3]['payload']);
        $this->assertSame(['note' => 'ordinary'], $records[4]['payload']);
    }

    public function test_capture_environment_options_parse_comma_separated_patterns(): void
    {
        $old = [$_ENV['DECISIONS_CAPTURE_PAYLOAD_EXCEPT'] ?? null, $_ENV['DECISIONS_CAPTURE_BINDING_STRINGS'] ?? null];
        try {
            $_ENV['DECISIONS_CAPTURE_PAYLOAD_EXCEPT'] = ' bank/*, hr/*,api/identity/*, ';
            $_ENV['DECISIONS_CAPTURE_BINDING_STRINGS'] = 'scrubbed';
            $config = require __DIR__.'/../../config/decisions.php';
            $this->assertSame(['bank/*', 'hr/*', 'api/identity/*'], $config['capture']['payload_except']);
            $this->assertSame('scrubbed', $config['capture']['bindings_strings']);
        } finally {
            foreach (['DECISIONS_CAPTURE_PAYLOAD_EXCEPT', 'DECISIONS_CAPTURE_BINDING_STRINGS'] as $index => $key) {
                if ($old[$index] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $old[$index];
                }
            }
        }
    }

    private function recordQuery(string $sql, array $bindings): void
    {
        Event::dispatch(new QueryExecuted($sql, $bindings, 1, new Connection(new PDO('sqlite::memory:'), '', '', ['name' => 'sqlite'])));
    }

    private function records(string $type): array
    {
        app(Recorder::class)->flush();

        return $this->transportHttp()->recorded()->flatMap(fn ($pair) => json_decode(gzdecode($pair[0]->body()), true, flags: JSON_THROW_ON_ERROR)['records'])->where('t', $type)->values()->all();
    }
}
