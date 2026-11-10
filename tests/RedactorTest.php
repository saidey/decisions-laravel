<?php

namespace DecisionsAi\Laravel\Tests;

use DecisionsAi\Laravel\Redactor;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class RedactorTest extends TestCase
{
    public function test_nested_fields_and_headers_are_masked_case_insensitively(): void
    {
        $config = config('decisions');
        $config['capture']['payloads'] = true;
        $record = (new Redactor($config))->redact([
            'body' => ['nested' => [['password' => 'secret', 'safe' => 'visible']], 'API_KEY' => 'key'],
            'headers' => ['Authorization' => 'Bearer secret', 'Cookie' => ['session=secret'], 'Accept' => 'application/json'],
        ]);

        $this->assertSame('[REDACTED]', $record['body']['nested'][0]['password']);
        $this->assertSame('[REDACTED]', $record['body']['API_KEY']);
        $this->assertSame('visible', $record['body']['nested'][0]['safe']);
        $this->assertSame('[REDACTED]', $record['headers']['Authorization']);
        $this->assertSame('[REDACTED]', $record['headers']['Cookie']);
        $this->assertSame('application/json', $record['headers']['Accept']);
    }

    public function test_capture_flags_remove_bodies_bindings_and_optional_source(): void
    {
        $config = config('decisions');
        $config['capture']['source'] = false;
        $record = (new Redactor($config))->redact([
            'body' => 'private', 'payload' => ['private'], 'bindings' => ['private'],
            'request' => ['body' => 'private'],
            'trace_frames' => [['file' => 'app/X.php', 'source' => ['10' => 'private']]],
        ]);

        $this->assertArrayNotHasKey('body', $record);
        $this->assertArrayNotHasKey('payload', $record);
        $this->assertArrayNotHasKey('bindings', $record);
        $this->assertArrayNotHasKey('body', $record['request']);
        $this->assertArrayNotHasKey('source', $record['trace_frames'][0]);
    }

    public function test_opt_in_flags_keep_redacted_payloads_bindings_source_and_user_details(): void
    {
        $config = config('decisions');
        $config['capture'] = ['payloads' => true, 'bindings' => true, 'source' => true];
        $config['user_details'] = true;
        $record = (new Redactor($config))->redact([
            'body' => ['password' => 'secret'], 'bindings' => [42],
            'trace_frames' => [['source' => ['10' => 'code']]],
            'user_name' => 'Name', 'user_email' => 'name@example.test',
        ]);

        $this->assertSame(['password' => '[REDACTED]'], $record['body']);
        $this->assertSame([42], $record['bindings']);
        $this->assertSame('code', $record['trace_frames'][0]['source']['10']);
        $this->assertSame('Name', $record['user_name']);
        $this->assertSame('name@example.test', $record['user_email']);
    }

    public function test_custom_fields_and_headers_are_supported(): void
    {
        $config = config('decisions');
        $config['redact']['fields'][] = 'pin';
        $config['redact']['headers'][] = 'x-private';
        $record = (new Redactor($config))->redact(['context' => ['PIN' => '1234'], 'headers' => ['X-Private' => 'secret']]);
        $this->assertSame('[REDACTED]', $record['context']['PIN']);
        $this->assertSame('[REDACTED]', $record['headers']['X-Private']);
    }

    public function test_recursive_input_is_contained_without_throwing(): void
    {
        $record = ['t' => 'log'];
        $record['context'] = &$record;
        $this->assertSame([], (new Redactor)->redact($record));
    }

    public function test_objects_are_normalized_before_recursive_privacy_and_redaction(): void
    {
        $arrayable = new class implements Arrayable
        {
            public function toArray(): array
            {
                return ['password' => 'secret', 'body' => 'private', 'user_email' => 'private@example.test'];
            }
        };
        $serializable = new class($arrayable) implements JsonSerializable
        {
            public function __construct(private Arrayable $credentials) {}

            public function jsonSerialize(): mixed
            {
                return ['credentials' => $this->credentials, 'token' => 'secret', 'payload' => 'private'];
            }
        };
        $record = (new Redactor)->redact([
            'context' => ['plain' => (object) ['password' => 'secret'], 'nested' => $serializable],
            'user' => new class implements JsonSerializable
            {
                public function jsonSerialize(): mixed
                {
                    return ['id' => '42', 'name' => 'Private', 'email' => 'private@example.test', 'password' => 'secret'];
                }
            },
        ]);
        $this->assertSame(['class' => 'stdClass'], $record['context']['plain']);
        $this->assertSame(['password' => '[REDACTED]'], $record['context']['nested']['credentials']);
        $this->assertSame('[REDACTED]', $record['context']['nested']['token']);
        $this->assertArrayNotHasKey('payload', $record['context']['nested']);
        $this->assertSame(['id' => '42'], $record['user']);
        $this->assertStringNotContainsString('secret', json_encode($record));
        $this->assertStringNotContainsString('private', json_encode($record));
    }

    public function test_json_serializable_takes_precedence_over_arrayable(): void
    {
        $object = new class implements Arrayable, JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['safe' => 'json', 'password' => 'secret'];
            }

            public function toArray(): array
            {
                throw new \RuntimeException('Must prefer jsonSerialize.');
            }
        };
        $this->assertSame(['value' => ['safe' => 'json', 'password' => '[REDACTED]']], (new Redactor)->redact(['value' => $object]));
    }

    public function test_serializable_cycles_and_throwables_are_contained(): void
    {
        $cycle = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['self' => $this];
            }
        };
        $self = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return $this;
            }
        };
        $throws = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('serializer failed');
            }
        };
        foreach ([$cycle, $self, $throws] as $object) {
            $this->assertSame([], (new Redactor)->redact(['context' => $object]));
        }
    }

    public function test_redaction_depth_is_limited_to_ten(): void
    {
        $record = ['safe' => 'visible'];
        for ($depth = 0; $depth < 10; $depth++) {
            $record = ['nested' => $record];
        }
        $this->assertSame($record, (new Redactor)->redact($record));
        $this->assertSame([], (new Redactor)->redact(['nested' => $record]));
    }
}
