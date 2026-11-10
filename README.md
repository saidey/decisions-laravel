# decisions-ai/laravel

Laravel client core for decisions.site monitoring. Supports PHP 8.2+ and Laravel 11, 12, and 13 (Laravel 13 requires PHP 8.3+). Requires the cURL and zlib PHP extensions.

## Install

```sh
composer require decisions-ai/laravel
```

Laravel discovers the service provider automatically. Set your environment token:

```dotenv
DECISIONS_TOKEN=your-environment-token
```

Optional configuration:

| Variable | Default | Purpose |
| --- | --- | --- |
| `DECISIONS_ENABLED` | Token is set | Enable recording and delivery. |
| `DECISIONS_TOKEN` | Unset | Environment ingest token. |
| `DECISIONS_URL` | `https://ingest.decisions.site` | Ingest base URL; batches go to `/ingest/v1`. |
| `DECISIONS_ENVIRONMENT` | `app.env` | Environment label. |
| `DECISIONS_DEPLOY` | Unset | Deployment identifier, such as a commit SHA. |
| `DECISIONS_USER_DETAILS` | `false` | Allow user names and email addresses alongside IDs. |

Publish the configuration to adjust privacy, buffer size, sampling, and ignore lists:

```sh
php artisan vendor:publish --tag=decisions-config
```

`config/decisions.php` defines `sample.{type}` (all `1.0`, including exceptions), `ignore.paths`, `ignore.commands`, `ignore.jobs`, `ignore.exceptions`, and a buffer of 1,000 records. Sampling and ignore settings are provided for the sensors; this core does not implement sensors.

Request bodies and query bindings are excluded by default (`capture.payloads=false`, `capture.bindings=false`). Exception source context is enabled (`capture.source=true`). Sensitive fields and headers are recursively masked as `[REDACTED]`, case insensitively. Review the redaction lists before enabling payload capture or user details.

## Client core

This package currently supplies the recorder, transport, redactor, and lifecycle hooks. Event sensors are a separate implementation step. You can record wire-format records directly:

```php
use DecisionsAi\Laravel\Recorder;

app(Recorder::class)->record([
    't' => 'log',
    'level' => 'info',
    'message' => 'Order synced',
]);
```

The recorder supplies the current trace UUID and a float Unix timestamp when absent. It flushes when the buffer fills, at application termination, and after a queue job or console command finishes or fails. Record 1,001 begins the next batch after the first 1,000 are sent. Queue jobs, console commands, and Octane requests begin fresh traces; Octane request termination flushes the request's batch.

`Recorder::startTrace()` flushes pending records before starting a new trace. `Recorder::flush()` can also be called explicitly. Batches use wire format v1, gzip JSON, and a bearer token, with a one-second connection timeout and a one-second total timeout. Failed batches are discarded without retries. `Recorder::droppedRecords()` exposes the in-process count of failed records. Public entry points contain exceptions so monitoring cannot throw into the host app.

## Development

```sh
composer install
vendor/bin/phpunit
vendor/bin/pint src config tests
```

Tests use HTTP fakes and loopback sockets, including a real server that accepts a connection without sending a response. They do not call decisions.site or use a database. GitHub Actions covers Laravel 11 / Testbench 9, Laravel 12 / Testbench 10, and Laravel 13 / Testbench 11 with PHPUnit 11.
