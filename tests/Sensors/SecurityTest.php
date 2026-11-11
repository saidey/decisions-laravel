<?php

namespace DecisionsAi\Laravel\Tests\Sensors;

use DecisionsAi\Laravel\DecisionsServiceProvider;
use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Sensors\Security;
use DecisionsAi\Laravel\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;

class SecurityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('decisions.security.capture', ! str_contains($this->name(), 'capture_off'));
        $app['config']->set('decisions.security.token_models', [SecurityToken::class]);
        $app['config']->set('decisions.security.watch_models', [SecuritySetting::class]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('decisions.user_details', true);
        $app['config']->set('app.url', 'http://localhost');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->transportHttp()->fake(['*' => $this->transportHttp()->response('', 202)]);
    }

    public function test_capture_off_keeps_request_shape_and_registers_no_security_listeners(): void
    {
        Event::dispatch(new Failed('web', null, ['email' => 'alice@example.test', 'password' => 'attempted-secret']));
        Event::dispatch('eloquent.created: '.SecurityToken::class, [new SecurityToken(['token' => 'secret'])]);
        Event::dispatch('eloquent.updated: '.SecuritySetting::class, [new SecuritySetting(['value' => 'secret'])]);
        app(Security::class)->posture();
        $this->get('/.env')->assertNotFound();

        $this->assertSame([], $this->records('security'));
        $request = $this->records('request')[0];
        $this->assertArrayNotHasKey('ip', $request);
        $this->assertArrayNotHasKey('ua', $request);
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/batch-v1.json'), true);
        $expected = collect($fixture['records'])->firstWhere('t', 'request');
        unset($expected['user_email']);
        $expected['user_name'] = null;
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($request));
        $this->assertFalse(Event::hasListeners(Failed::class));
    }

    public static function identifiers(): array
    {
        return [
            'email first' => [['email' => ' Alice@Example.test ', 'username' => 'other'], 'alice@example.test'],
            'username' => [['username' => ' Alice '], 'alice'],
            'name' => [['name' => ' Alice '], 'alice'],
            'phone' => [['phone' => ' +9607771234 '], '+9607771234'],
        ];
    }

    #[DataProvider('identifiers')]
    public function test_login_failed_sends_only_identifier_hmac(array $credentials, string $normalized): void
    {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.8', 'HTTP_USER_AGENT' => str_repeat('a', 300)]);
        $this->app->instance('request', $request);
        Event::dispatch(new Failed('web', null, [...$credentials, 'password' => 'attempted-secret']));
        $record = $this->security('login_failed')[0];

        $this->assertSame(hash_hmac('sha256', $normalized, 'test-token'), $record['identifier']);
        $this->assertSame('web', $record['guard']);
        $this->assertSame('203.0.113.8', $record['ip']);
        $this->assertLessThanOrEqual(255, strlen($record['ua']));
        $this->assertIsFloat($record['ts']);
        $this->assertSame($record['ts'], $record['at']);
        $payload = json_encode($this->records('security'));
        foreach ([...array_values($credentials), 'attempted-secret', 'password', 'credentials'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
        $this->assertArrayNotHasKey('user_email', $record);
        $this->assertArrayNotHasKey('user_name', $record);
    }

    public function test_auth_events_keep_event_user_after_logout_and_handle_optional_fortify_events(): void
    {
        $user = new GenericUser(['id' => 42, 'name' => 'Private', 'email' => 'alice@example.test']);
        foreach ([new Login('web', $user, false), new Logout('web', $user), new PasswordReset($user), new Verified($user)] as $event) {
            Event::dispatch($event);
        }
        foreach (['Enabled', 'Disabled'] as $suffix) {
            Event::dispatch('Laravel\\Fortify\\Events\\TwoFactorAuthentication'.$suffix, [(object) ['user' => $user]]);
        }
        $request = Request::create('/login', server: ['REMOTE_ADDR' => '203.0.113.9']);
        Event::dispatch(new Lockout($request));

        $records = array_values(array_filter($this->records('security'), fn ($record) => $record['kind'] !== 'posture'));
        $this->assertSame(['login', 'logout', 'password_reset', 'verified', 'two_factor_enabled', 'two_factor_disabled', 'lockout'], array_column($records, 'kind'));
        $this->assertSame(array_fill(0, 6, '42'), array_column(array_slice($records, 0, 6), 'user'));
        $this->assertSame('203.0.113.9', $records[6]['ip']);
        $this->assertStringNotContainsString('alice@example.test', json_encode($records));
    }

    public static function responseKinds(): array
    {
        return [[403, 'forbidden'], [419, 'csrf'], [429, 'rate_limited'], [401, 'unauthenticated']];
    }

    #[DataProvider('responseKinds')]
    public function test_response_kinds_and_request_network_context(int $status, string $kind): void
    {
        Route::get('/protected/{id}', fn () => response('', $status))->name('protected');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'Security test'])->get('/protected/secret')->assertStatus($status);

        $record = $this->security($kind)[0];
        $this->assertSame('protected', $record['route']);
        $this->assertSame('203.0.113.10', $record['ip']);
        $this->assertSame('Security test', $record['ua']);
        $request = $this->records('request')[0];
        $this->assertSame($record['ip'], $request['ip']);
        $this->assertSame($record['ua'], $request['ua']);
        $this->assertArrayNotHasKey('key', $record);
    }

    public function test_exception_responses_produce_security_kinds_once(): void
    {
        Route::get('/denied', fn () => throw new AuthorizationException);
        Route::get('/limited', fn () => throw new ThrottleRequestsException);
        $this->get('/denied')->assertForbidden();
        $this->get('/limited')->assertStatus(429);

        $this->assertCount(1, $this->security('forbidden'));
        $this->assertCount(1, $this->security('rate_limited'));
        $this->assertSame('/denied', $this->security('forbidden')[0]['route']);
    }

    public function test_available_throttle_key_is_hashed(): void
    {
        $request = Request::create('/limited');
        $request->attributes->set('throttle_key', 'account:alice@example.test');
        Event::dispatch(new RequestHandled($request, response('', 429)));

        $record = $this->security('rate_limited')[0];
        $this->assertSame(hash_hmac('sha256', 'account:alice@example.test', 'test-token'), $record['key']);
        $this->assertStringNotContainsString('alice@example.test', json_encode($this->records('security')));
    }

    public function test_probe_matches_case_insensitively_and_redacts_path(): void
    {
        $this->get('/.ENV/'.str_repeat('x', 32))->assertNotFound();
        $this->get('/ordinary-missing')->assertNotFound();
        $record = $this->security('probe')[0];

        $this->assertCount(1, $this->security('probe'));
        $this->assertSame('/.ENV/{redacted}', $record['path']);
        $this->assertSame('/.ENV/{redacted}', $record['route']);
        config(['decisions.security.probes' => ['/custom-probe']]);
        $this->get('/custom-probe')->assertNotFound();
        $this->get('/.git')->assertNotFound();
        $this->assertCount(2, $this->security('probe'));
    }

    public function test_token_created_and_deleted_send_allowlisted_fields_only(): void
    {
        $model = new SecurityToken(['id' => 5, 'tokenable_id' => 42, 'tokenable_type' => 'App\\Models\\User', 'name' => 'CLI alice@example.test', 'abilities' => ['read', 'write'], 'token' => 'never-export-token']);
        foreach (['created', 'deleted'] as $action) {
            Event::dispatch('eloquent.'.$action.': '.SecurityToken::class, [$model]);
        }
        foreach (['token_created', 'token_deleted'] as $kind) {
            $record = $this->security($kind)[0];
            $this->assertSame(42, $record['tokenable_id']);
            $this->assertSame('App\\Models\\User', $record['tokenable_type']);
            $this->assertSame('CLI [REDACTED]', $record['name']);
            $this->assertSame(['read', 'write'], $record['abilities']);
            $this->assertArrayNotHasKey('token', $record);
        }
        $this->assertStringNotContainsString('never-export-token', json_encode($this->records('security')));
    }

    public function test_privileged_events_send_actor_subject_and_attribute_names_only(): void
    {
        $this->actingAs(new GenericUser(['id' => 7]));
        $model = new SecuritySetting(['id' => 9, 'role' => 'old-private-role', 'password' => 'never-export-password']);
        Event::dispatch('eloquent.created: '.SecuritySetting::class, [$model]);
        $model->syncOriginal();
        $model->setAttribute('role', 'new-private-role');
        $model->syncChanges();
        Event::dispatch('eloquent.updated: '.SecuritySetting::class, [$model]);
        Event::dispatch('eloquent.deleted: '.SecuritySetting::class, [$model]);
        $records = $this->security('privileged');

        $this->assertSame(['created', 'updated', 'deleted'], array_column($records, 'action'));
        $this->assertSame('7', $records[1]['actor']);
        $this->assertSame(['class' => SecuritySetting::class, 'key' => 9], $records[1]['subject']);
        $this->assertSame(['role'], $records[1]['changed']);
        $this->assertSame(['id', 'role', 'password'], $records[0]['changed']);
        foreach (['old-private-role', 'new-private-role', 'never-export-password'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($records));
        }
        Event::dispatch('eloquent.created: '.SecurityUnwatched::class, [new SecurityUnwatched]);
        $this->assertCount(3, $this->security('privileged'));
    }

    public function test_posture_is_recorded_at_boot_and_throttled_for_one_hour(): void
    {
        $sensor = app(Security::class);
        $sensor->posture();
        $first = $this->security('posture')[0];
        $this->assertCount(1, $this->security('posture'));
        $this->assertSame(config('app.debug'), $first['app']['debug']);
        $this->assertSame(config('app.env'), $first['app']['env']);
        $this->assertSame(config('session.secure'), $first['session']['secure']);
        $this->assertSame('0.2.1', $first['client_version']);
        $this->assertSame(DecisionsServiceProvider::VERSION, $first['client_version']);
        $this->assertSame(PHP_VERSION, $first['php_version']);
        $this->assertSame(app()->version(), $first['laravel_version']);
        $this->assertFalse($first['https_forced']);

        $this->travel(3599)->seconds();
        $sensor->posture();
        $this->assertCount(1, $this->security('posture'));
        $this->travel(2)->seconds();
        URL::forceScheme('https');
        $sensor->posture();
        $this->assertCount(2, $this->security('posture'));
        $this->assertTrue($this->security('posture')[1]['https_forced']);
        $this->app->forgetInstance(Security::class);
        app(Security::class)->posture();
        $this->assertCount(2, $this->security('posture'));
    }

    public function test_posture_cache_outage_fails_open_and_still_throttles_locally(): void
    {
        $this->travel(3601)->seconds();
        config(['cache.default' => 'missing-security-cache', 'app.url' => 'https://example.test']);
        app(Security::class)->posture();
        app(Security::class)->posture();

        $this->assertCount(1, $this->security('posture'));
        $this->assertTrue($this->security('posture')[0]['https_forced']);
    }

    private function security(string $kind): array
    {
        return array_values(array_filter($this->records('security'), fn ($record) => $record['kind'] === $kind));
    }

    private function records(string $type): array
    {
        app(Recorder::class)->flush();

        return $this->transportHttp()->recorded()
            ->flatMap(fn ($pair) => json_decode(gzdecode($pair[0]->body()), true, flags: JSON_THROW_ON_ERROR)['records'])
            ->filter(fn ($record) => $record['t'] === $type)->values()->all();
    }
}

class SecurityToken extends Model
{
    protected $guarded = [];

    protected $casts = ['abilities' => 'array'];
}

class SecuritySetting extends Model
{
    protected $guarded = [];
}

class SecurityUnwatched extends Model {}
