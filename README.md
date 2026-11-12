# decisions-ai/laravel

Laravel client for decisions.site monitoring. Supports Laravel 13 on PHP 8.3+. Requires the cURL and zlib PHP extensions.

## Install

```sh
composer require decisions-ai/laravel
```

Laravel discovers the service provider and registers the sensors automatically. Set the ingest token for your decisions.site project environment:

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
| `DECISIONS_CAPTURE_BINDING_STRINGS` | `masked` | With binding capture enabled, mask non-numeric strings as `?`; use `scrubbed` for column-aware redaction and value scrubbing. |
| `DECISIONS_CAPTURE_PAYLOAD_EXCEPT` | Empty | Comma-separated request path patterns whose payloads are excluded, such as `bank/*,hr/*,api/identity/*`. |
| `DECISIONS_CAPTURE_CACHE_KEYS` | `false` | Capture scrubbed cache keys instead of hashed keys; session keys remain hashed. |
| `DECISIONS_SECURITY_CAPTURE` | `false` | Enable security signals, hourly posture, and request IP/user agent capture. |
| `DECISIONS_SECURITY_ENFORCE` | `false` | Enable cached IP blocking and approved response commands. |
| `DECISIONS_SECURITY_IP_HEADER` | Unset | Trusted edge headers, comma-separated and tried in order, for captured and enforced IPs; otherwise `$request->ip()`. |

Publish the configuration to adjust privacy, buffer size, sampling, and ignore lists:

```sh
php artisan vendor:publish --tag=decisions-config
```

In the published `config/decisions.php`, each `sample.{type}` is a probability from `0.0` (off) to `1.0` (all records). Sampling draws independently for every record. All types default to `1.0`; exceptions use their own rate regardless of the surrounding request/job/command's rate.

Security capture is opt-in through `security.capture` / `DECISIONS_SECURITY_CAPTURE`. It adds `security` records for authentication (including Fortify two-factor events when installed), 403/419/429/401 responses, matching 404 probes, token lifecycle, configured privileged model changes, and application posture. Security records include `kind`, `ts` (the same Unix timestamp as wire-format `at`), IP, user agent (at most 255 bytes), and user ID without name or email. Request records gain `ip` from Laravel's trusted-proxy-aware `$request->ip()` and `ua` only while enabled.

Configure these keys under `security` in the published config:

| Key | Default | Purpose |
| --- | --- | --- |
| `capture` | `false` | Enable all security capture. |
| `probes` | `/.env`, `/.git`, `/wp-admin`, `/wp-login.php`, `/xmlrpc.php`, `phpmyadmin`, `/vendor/phpunit`, `../`, `/.aws`, `/server-status`, `/actuator`, `/cgi-bin` | Case-insensitive path substrings for 404 probes; exported paths pass through the redactor. |
| `token_models` | `null`: Sanctum's configured token model (`Sanctum::usePersonalAccessTokenModel()`), read after boot; `[]` when Sanctum is absent | Model class names whose created/deleted events send `tokenable_id`, `tokenable_type`, scrubbed name and abilities; token values are excluded. |
| `watch_models` | `[]` | Model class names whose created/updated/deleted events send actor ID, subject class/key, action, and changed attribute names only. |

Failed logins send only an HMAC-SHA256 identifier, keyed with `DECISIONS_TOKEN`, after trimming and lowercasing the first present credential among `email`, `username`, `name`, and `phone`. Credentials and attempted passwords are excluded. Rate-limit records omit `key` unless the application supplies a `throttle_key` request attribute; that value is HMAC-SHA256 hashed with the same environment token. Laravel's standard throttle exception does not expose its limiter key.

Posture reports app debug/environment, secure-session-cookie configuration, HTTPS forcing (HTTPS `app.url` or the URL generator's forced scheme), and client/PHP/Laravel versions. It runs at boot and checks again on requests, auth/model events, commands, and jobs, at most hourly per process using the default configured cache store and a local timestamp. Cache failure still permits a locally throttled report. Composer advisory counts are deferred.

```php
'sample' => [
    // Keep the other published type entries as needed.
    'query' => 0.1,
    'log' => 0.5,
    'exception' => 1.0,
],
'ignore' => [
    'paths' => ['/health', '/internal/*'],
    'commands' => ['internal:*'],
    'jobs' => [App\Jobs\InternalSync::class],
    'exceptions' => [Illuminate\Auth\AuthenticationException::class],
],
```

Path, command and job patterns support `*`. Paths match without the query string, with or without a leading slash. Ignoring a request, command or processing job suppresses its trace, including logs, queries and exceptions. An ignored synchronous job suppresses only that job's records and restores its parent request or command trace afterward. Jobs inside ignored requests remain ignored. Ignored exception classes also match subclasses; they suppress exception records only.

Request bodies and query bindings are excluded by default (`capture.payloads=false`, `capture.bindings=false`). When enabling payloads, set `capture.payload_except` to an array such as `['bank/*', 'hr/*', 'api/identity/*']`, or use `DECISIONS_CAPTURE_PAYLOAD_EXCEPT=bank/*,hr/*,api/identity/*`. Patterns use Laravel's `Request::is()` syntax and omit the leading slash. Matching requests still produce request events, with no payload.

With binding capture enabled, `capture.bindings_strings` defaults to `masked` (`DECISIONS_CAPTURE_BINDING_STRINGS`): every non-numeric string binding becomes `?`. Set it to `scrubbed` to retain ordinary strings while masking bindings compared to or inserted into sensitive columns, including comparisons, `IN` lists, inserts and updates. Unknown column contexts fall back to value scrubbing. Numbers, booleans and nulls are retained. Query exceptions and logs with query exception context use the same sanitized bindings alongside the SQL template and driver error code; raw driver messages are excluded. Already stringified database errors without exception context always send a generic redacted summary.

Payload field matching also masks identity-document names, including national IDs, passports, identity/card numbers, NIC, TIN/tax IDs and driving licence/license numbers, including normalized and nested names. Plain `id`, `user_id` and `team_id` remain available. Exception and log messages and all nested context strings mask Maldivian national IDs (`A123456`) and passport numbers (one letter and seven digits) as whole tokens, email addresses, phone-shaped values (7+ digits, including spaces or dashes), bearer tokens, JWT-shaped values, long hex/base64 strings (32+ characters), and credential pairs such as `password=…` and `token=…`. UUIDs and references such as `INV-123456` remain available. Numeric values remain numeric. Absolute URLs in these strings also have credentials, sensitive query parameters (including signed URL signatures and expiry), and secret path segments redacted.

Cache keys are private by default (`capture.cache_keys=false`). Records keep only a scrub-safe prefix before the first `:` and the first 12 hex characters of an HMAC-SHA256 of the full key, salted with `app.key`, such as `phone-code:3f9a1c0b2d4e`. Grouping stays stable within an app until its key changes. If `app.key` is unset, keys are fully redacted. Set `DECISIONS_CAPTURE_CACHE_KEYS=true` to capture keys with value scrubbing applied. Keys from the configured session cache store, 40-character alphanumeric session-shaped keys, and keys beginning with the configured session cookie or prefix always use the hashed form without a prefix.

Matched incoming requests send the route template as both `route` and `path`, without actual route parameters. Unmatched incoming paths and outgoing URL paths replace secret-shaped segments (including 24+ character tokens and Telegram bot credentials) with `{redacted}`. URL user credentials and sensitive query parameters are redacted. Scheduled inventory and run records send only the Artisan command name, executable basename, or explicit shell/callback task description; arguments and options are excluded.

Exception source context is opt-in (`capture.source=false` by default; set `DECISIONS_CAPTURE_SOURCE=true` to enable it). Only canonical files inside the application base directory and outside vendor directories are read. Symlinks outside that boundary are excluded. Captured source lines use the same value scrubbing as messages, including quoted values assigned to sensitive keys. Sensitive fields and headers are recursively masked as `[REDACTED]`, case insensitively. User IDs are captured when available; names and emails require `DECISIONS_USER_DETAILS=true`. Serializable and Arrayable objects are normalized before redaction; other objects expose only their class. Recursion stops at depth 10. Review redaction lists, task descriptions, and capture settings before enabling optional data. Pattern matching cannot recognize every secret; avoid putting sensitive values in messages or descriptions.

## Security response enforcement

Enforcement is independently opt-in with `DECISIONS_SECURITY_ENFORCE=true`. It prepends `DecisionsAi\Laravel\Http\BlockList` to the global middleware stack before sessions and auth. Exact IPv4/IPv6 addresses and CIDRs are checked only against the default configured cache store. Invalid entries, IPv4 ranges broader than /16, and IPv6 ranges broader than /32 are dropped while valid feed entries are retained. Expired blocks are ignored, and cache failures allow requests through. Blocked requests return plain-text `403 Forbidden` without running later middleware, setting session cookies, or recording request/security events. Use a shared non-database cache with lock support, such as Redis, to keep checks free of database access; a database-backed cache itself queries the database.

Apps on DigitalOcean App Platform must set `DECISIONS_SECURITY_IP_HEADER=do-connecting-ip`; behind Cloudflare as well, use `cf-connecting-ip,do-connecting-ip` and lock the origin to Cloudflare, or a client that reaches the origin directly can send its own `cf-connecting-ip`. Configure a header only when your platform overwrites it with a trusted client IP. Captured security events, request records and enforcement all use the same IP. Each header's trimmed first value is validated; the next header, then `$request->ip()`, is used if it is invalid; arbitrary headers are ignored by default. This middleware runs before Laravel's proxy middleware, so configure the platform header when needed.

The client pulls `GET /ingest/v1/security` and acknowledges commands through `POST /ingest/v1/security/commands/{id}` using `DECISIONS_URL`, the environment bearer token, and the same one-second connection/total timeouts as ingest. There is no inbound command endpoint. Run Laravel's scheduler for minute refreshes; request termination also refreshes stale data after the response is sent. Refresh defaults to 60 seconds and honors the server's `refresh` interval. A cache lock prevents concurrent refreshes. Fetch failures keep the previous list, whose entries remain subject to their server-supplied `expires_at` timestamps (normally 24 hours).

Configure application-specific handlers in `security.handlers`:

```php
'handlers' => [
    'logout_user' => App\Security\LogoutUser::class, // Optional callable or invokable class.
    'disable_user' => App\Security\DisableUser::class, // Required invokable class to disable users.
],
```

Each handler receives the string user ID, for example `public function __invoke(string $userId): void`. `logout_user` deletes that user's database sessions when `session.driver=database`, rotates the default auth provider model's remember token when its column exists, and also invokes the configured logout handler. Both `logout_user` and `disable_user` delete the default auth provider user's tokens before acknowledging completion when the model has a `tokens()` relation, such as Laravel Sanctum's `HasApiTokens`; models without that relation are supported. Use the logout handler to revoke sessions or tokens managed elsewhere. `disable_user` requires your handler to disable the account; without one it acknowledges failure. Commands execute only when their age is less than 600 seconds; age 600 seconds or more is stale. Unknown types are rejected as unsupported. IDs are reserved in the default configured cache store before execution, and results, including failures, are cached for 15 minutes. Repeated IDs resend the cached acknowledgement without executing again, so a lost acknowledgement can be retried. Exceptions produce a short failed acknowledgement without exporting exception details. When enforcement is off, neither middleware nor refresh scheduling is registered and no security fetches occur.

## Sensors and recording

The provider automatically captures HTTP requests, reported exceptions, processed/failed/released queue jobs, console commands, scheduled task outcomes, schedule inventory, database queries, cache operations, sent mail, sent notifications, outgoing HTTP responses/connection failures, and logs. Background scheduled tasks are recorded when Laravel reports completion, not when launched. Background completion has no framework runtime value, so its duration is `0`. Schedule inventory is reported at most hourly per deployment. Logs carry the actual Monolog channel name; set a channel's `name` in `config/logging.php` if you want a distinct filter label.

You can also record wire-format records directly:

```php
use DecisionsAi\Laravel\Recorder;

app(Recorder::class)->record([
    't' => 'log',
    'level' => 'info',
    'message' => 'Order synced',
]);
```

The recorder supplies the current trace UUID and a float Unix timestamp when absent. It flushes when the buffer fills (default 1,000 records), before the next record would take encoded batch JSON over 900 KB, at application termination, and after a queue job or console command finishes or fails. Requests, queue jobs and console commands begin fresh traces, including Octane request lifecycles.

`Recorder::startTrace()` flushes pending records before starting a new trace. `Recorder::flush()` can also be called explicitly. Batches use wire format v1, gzip JSON, and a bearer token, with a one-second connection timeout and a one-second total timeout. Failed batches are discarded without retries. `Recorder::droppedRecords()` exposes the in-process count of failed or oversized records; intentional sampling and ignores do not increment it. Strings are limited to 8 KB, log messages to 16 KB, and encoded context to 32 KB (oversized context becomes `{"truncated":true}`). Encoded records over 64 KB are dropped. Limits use 1 KB = 1,024 bytes. Source snippets read at most five lines on either side, skip vendor files and files over 2 MB, and reuse at most 30 file handles per trace. Flushes are synchronous; each delivery can take up to one second, and a long unit of work can cause multiple deliveries. Public entry points contain exceptions so monitoring cannot throw into the host app.

## Development

```sh
composer install
vendor/bin/phpunit
vendor/bin/pint src config tests
```

Tests use HTTP fakes, isolated in-memory SQLite for response commands, and loopback sockets, including a real server that accepts a connection without sending a response. They do not call decisions.site or use an application database. GitHub Actions runs the suite on Laravel 13 / Testbench 11 with PHP 8.3 and 8.4.
