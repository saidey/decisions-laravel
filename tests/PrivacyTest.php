<?php

namespace DecisionsAi\Laravel\Tests;

use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Redactor;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;

class PrivacyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'privacy-test-app-key');
        $app['config']->set('logging.channels.privacy', [
            'driver' => 'monolog', 'name' => 'privacy', 'handler' => TestHandler::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 202)]);
    }

    public function test_default_cache_keys_use_stable_app_salted_hashes_on_the_wire(): void
    {
        $this->assertFalse(config('decisions.capture.cache_keys'));
        $key = 'phone-code:+9607771234';
        $cache = Cache::store('array');
        $cache->put($key, 'private', 60);
        $cache->get($key);
        $cache->put('phone-code:+9607774321', 'private', 60);
        config(['app.key' => 'another-app-key']);
        $cache->put($key, 'private', 60);
        [$records, $json] = $this->wire();

        $hash = 'phone-code:'.substr(hash_hmac('sha256', $key, 'privacy-test-app-key'), 0, 12);
        $this->assertSame($hash, $records[0]['key']);
        $this->assertSame($hash, $records[1]['key']);
        $this->assertNotSame($hash, $records[2]['key']);
        $this->assertSame('phone-code:'.substr(hash_hmac('sha256', $key, 'another-app-key'), 0, 12), $records[3]['key']);
        $this->assertStringNotContainsString('9607771234', $json);
        $this->assertStringNotContainsString('+960', $json);
    }

    public function test_unsafe_cache_prefixes_and_missing_app_keys_do_not_leak(): void
    {
        foreach (['a@b.mv:code', '+9607771234:code', 'plain-key'] as $key) {
            Cache::store('array')->put($key, 'private', 60);
        }
        config(['app.key' => null]);
        Cache::store('array')->put('phone-code:+9607771234', 'private', 60);
        [$records, $json] = $this->wire();

        foreach (array_slice($records, 0, 3) as $record) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $record['key']);
        }
        $this->assertSame('[REDACTED]', $records[3]['key']);
        $this->assertStringNotContainsString('a@b.mv', $json);
        $this->assertStringNotContainsString('9607771234', $json);
    }

    public static function cacheCaptureModes(): array
    {
        return ['default' => [false], 'opt in' => [true]];
    }

    #[DataProvider('cacheCaptureModes')]
    public function test_session_store_reads_never_export_session_keys(bool $capture): void
    {
        config([
            'decisions.capture.cache_keys' => $capture,
            'session.driver' => 'redis',
            'session.store' => 'array',
        ]);
        $sessionId = str_repeat('sA12', 10);
        $handler = new CacheBasedSessionHandler(Cache::store('array'), 120);
        $handler->read($sessionId);
        $handler->read('short-session-key');
        [$records, $json] = $this->wire();

        $this->assertCount(2, $records);
        $this->assertSame(['miss', 'miss'], array_column($records, 'op'));
        $this->assertSame(substr(hash_hmac('sha256', $sessionId, 'privacy-test-app-key'), 0, 12), $records[0]['key']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $records[1]['key']);
        $this->assertStringNotContainsString($sessionId, $json);
        $this->assertStringNotContainsString('short-session-key', $json);
    }

    public function test_opted_in_cache_keys_still_hash_session_shapes_and_prefixes(): void
    {
        config([
            'decisions.capture.cache_keys' => true,
            'session.driver' => 'database',
            'session.store' => null,
            'session.cookie' => 'custom-cookie:',
            'session.prefix' => 'custom-prefix:',
        ]);
        $keys = [str_repeat('sA12', 10), 'custom-cookie:short-secret', 'custom-prefix:short-secret'];
        foreach ($keys as $key) {
            Cache::store('array')->get($key);
        }
        [$records, $json] = $this->wire();

        foreach ($keys as $index => $key) {
            $this->assertSame(substr(hash_hmac('sha256', $key, 'privacy-test-app-key'), 0, 12), $records[$index]['key']);
            $this->assertStringNotContainsString($key, $json);
        }
        $this->assertStringNotContainsString('short-secret', $json);
    }

    public function test_opted_in_cache_keys_scrub_phones_and_emails(): void
    {
        config(['decisions.capture.cache_keys' => true, 'session.driver' => 'database', 'session.store' => null]);
        foreach (['phone-code:+9607771234', 'mail:a@b.mv', 'orders:42'] as $key) {
            Cache::store('array')->put($key, 'private', 60);
        }
        [$records, $json] = $this->wire();

        $this->assertSame(['phone-code:[REDACTED]', 'mail:[REDACTED]', 'orders:42'], array_column($records, 'key'));
        $this->assertStringNotContainsString('9607771234', $json);
        $this->assertStringNotContainsString('+960', $json);
        $this->assertStringNotContainsString('a@b.mv', $json);
    }

    public function test_log_context_scrubs_nested_strings_and_preserves_numeric_values(): void
    {
        $context = [
            'to' => 'a@b.mv',
            'url' => 'https://x.test/img?expires=1&signature=abc123def',
            'nested' => ['contact' => '+960 777-1234', 'id' => 9607771234, 'timestamp' => 1762761600.123],
            'object' => new class implements \JsonSerializable
            {
                public function jsonSerialize(): mixed
                {
                    return ['to' => 'nested@b.mv'];
                }
            },
        ];
        Log::channel('privacy')->info('Delivery', $context);
        [$records, $json] = $this->wire();

        $this->assertSame('[REDACTED]', $records[0]['context']['to']);
        $this->assertSame('https://x.test/img?expires=[redacted]&signature=[redacted]', $records[0]['context']['url']);
        $this->assertSame('[REDACTED]', $records[0]['context']['nested']['contact']);
        $this->assertSame(9607771234, $records[0]['context']['nested']['id']);
        $this->assertSame(1762761600.123, $records[0]['context']['nested']['timestamp']);
        $this->assertSame('[REDACTED]', $records[0]['context']['object']['to']);
        $this->assertStringNotContainsString('a@b.mv', $json);
        $this->assertStringNotContainsString('nested@b.mv', $json);
        $this->assertStringNotContainsString('abc123def', $json);
        $this->assertSame($context, Log::channel('privacy')->getLogger()->getHandlers()[0]->getRecords()[0]->context);
    }

    public static function signedUrlFields(): array
    {
        return array_map(fn ($field) => [$field], [
            'signature', 'SiGnAtUrE', 'expires', 'token', 'X-Amz-Signature', 'X-Amz-Credential', 'sig',
        ]);
    }

    #[DataProvider('signedUrlFields')]
    public function test_exception_context_scrubs_absolute_urls_recursively(string $field): void
    {
        app(Recorder::class)->record([
            't' => 'exception',
            'context' => ['nested' => [
                'to' => 'a@b.mv',
                'url' => 'https://user:private-pass@x.test/'.str_repeat('a', 24).'?'.$field.'=abc123def',
            ]],
        ]);
        [$records, $json] = $this->wire();

        $this->assertSame('[REDACTED]', $records[0]['context']['nested']['to']);
        foreach (['a@b.mv', 'abc123def', 'user:', 'private-pass', str_repeat('a', 24)] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringContainsString('{redacted}', $records[0]['context']['nested']['url']);
    }

    public static function recordTypes(): array
    {
        return array_map(fn ($type) => [$type], ['cache', 'log', 'job', 'notification', 'mail', 'http', 'exception']);
    }

    #[DataProvider('recordTypes')]
    public function test_every_record_type_recursively_masks_case_insensitive_fields(string $type): void
    {
        config([
            'decisions.capture.payloads' => true,
            'decisions.redact.fields' => [...config('decisions.redact.fields'), 'PrivateValue'],
        ]);
        app(Recorder::class)->record([
            't' => $type,
            'payload' => ['nested' => ['Password' => 'x', 'PRIVATEVALUE' => 'custom-secret', 'safe' => 'visible']],
        ]);
        [$records, $json] = $this->wire();

        $this->assertSame([
            'Password' => '[REDACTED]', 'PRIVATEVALUE' => '[REDACTED]', 'safe' => 'visible',
        ], $records[0]['payload']['nested']);
        $this->assertStringNotContainsString('"Password":"x"', $json);
        $this->assertStringNotContainsString('custom-secret', $json);
    }

    public static function phoneValues(): array
    {
        return [
            'local' => ['7771234'],
            'international' => ['+9607771234'],
            'spaced' => ['+960 777 1234'],
            'dashed' => ['960-777-1234'],
            'mixed' => ['+960 777-1234'],
        ];
    }

    #[DataProvider('phoneValues')]
    public function test_phone_scrubbing_applies_to_every_value_scrubber_caller(string $phone): void
    {
        $redactor = app(Redactor::class);
        $this->assertSame('Call [REDACTED].', $redactor->scrubValue('Call '.$phone.'.'));
        $this->assertSame('[REDACTED]', $redactor->exceptionMessage(new \RuntimeException($phone)));
        $this->assertSame('123456', $redactor->scrubValue('123456'));
        app(Recorder::class)->record(['t' => 'exception', 'message' => $phone]);
        Log::channel('privacy')->info($phone, ['nested' => ['phone' => $phone]]);
        [$records] = $this->wire();

        $this->assertSame(['[REDACTED]', '[REDACTED]'], array_column($records, 'message'));
        $this->assertSame('[REDACTED]', $records[1]['context']['nested']['phone']);
    }

    private function wire(): array
    {
        app(Recorder::class)->flush();
        $records = [];
        $json = '';
        foreach ($this->transportHttp()->recorded() as [$request]) {
            $this->assertTrue($request->hasHeader('Content-Encoding', 'gzip'));
            $body = gzdecode($request->body());
            $this->assertIsString($body);
            $batch = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $records = [...$records, ...$batch['records']];
            $json .= $body;
        }
        $this->assertNotEmpty($records);

        return [$records, $json];
    }

    public function test_phone_masking_spares_dates_and_uuids(): void
    {
        $redactor = $this->app->make(Redactor::class);
        $this->assertSame('call [REDACTED] now', $redactor->scrubValue('call +960 777-1234 now'));
        $this->assertSame('on 2026-11-10 at 19:44', $redactor->scrubValue('on 2026-11-10 at 19:44'));
        $this->assertSame('job order-12345678 and v1.2345678', $redactor->scrubValue('job order-12345678 and v1.2345678'));
    }

    public function test_url_fragments_are_never_exported(): void
    {
        $redactor = app(Redactor::class);
        $this->assertSame('https://files.example/img.png?X-Amz-Signature=[redacted]', $redactor->redactUrl('https://files.example/img.png?X-Amz-Signature=abc#private-fragment'));
        $this->assertSame('https://app.test/cb', $redactor->scrubValue('https://app.test/cb#access_token=secretvalue'));
        Http::fake(['files.example/*' => Http::response('ok')]);
        Http::get('https://files.example/img.png?X-Amz-Signature=abc#private-fragment');
        [$records, $json] = $this->wire();
        $this->assertStringNotContainsString('private-fragment', $json);
        foreach ($records as $record) {
            $this->assertStringNotContainsString('#', (string) ($record['url'] ?? ''));
        }
    }

    public function test_query_values_are_masked_by_name_pattern_and_by_value_shape(): void
    {
        $redactor = app(Redactor::class);
        // Unknown names that still say what they are.
        $this->assertSame('https://api.test/v1?client_secret=[redacted]&apiKey=[redacted]&X-Auth-Token=[redacted]&session_id=[redacted]&page=2',
            $redactor->redactUrl('https://api.test/v1?client_secret=s3&apiKey=k1&X-Auth-Token=t9&session_id=abc&page=2'));
        // Auth codes and one-time passwords.
        $this->assertSame('https://api.test/cb?code=[redacted]&state=[redacted]&otp=[redacted]',
            $redactor->redactUrl('https://api.test/cb?code=4/0AY0e&state=xyz&otp=123456'));
        // Innocent names carrying secret-shaped values: long opaque strings, JWTs, emails, phones.
        $this->assertSame('https://api.test/q?q=[redacted]&ref=[redacted]&to=[redacted]&msisdn=[redacted]&sort=name&id=42&uuid=9b2f0c1e-5d4a-4e7b-8f3a-2c1d0e9f8a7b',
            $redactor->redactUrl('https://api.test/q?q=opaque_7f3kQ2abcdefghijklmnopqrstuv&ref=eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln&to=a%40b.com&msisdn=%2B9607771234&sort=name&id=42&uuid=9b2f0c1e-5d4a-4e7b-8f3a-2c1d0e9f8a7b'));
        $this->assertSame('https://api.test/q?to=[redacted]', $redactor->redactUrl('https://api.test/q?to=a%2540b.com'));
        // Array-style and valueless parameters keep their shape.
        $this->assertSame('https://api.test/x?filter[token]=[redacted]&flag&tags[]=a',
            $redactor->redactUrl('https://api.test/x?filter[token]=abc&flag&tags[]=a'));
    }
}
