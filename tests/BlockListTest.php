<?php

namespace DecisionsAi\Laravel\Tests;

use Closure;
use DecisionsAi\Laravel\Http\BlockList;
use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\SecurityResponse;
use Illuminate\Auth\Authenticatable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;

class BlockListTest extends TestCase
{
    private array $payload = ['blocks' => [], 'commands' => [], 'refresh' => 60];

    private bool $fetchFails = false;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('decisions.security.enforce', ! str_contains($this->name(), 'enforce_off'));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', ResponseUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::swap($this->transportHttp());
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                if ($this->fetchFails) {
                    throw new RuntimeException('Connection failed');
                }

                return Http::response($this->payload);
            }

            return Http::response('', 202);
        });
    }

    public function test_enforce_off_registers_no_middleware_or_schedule_and_never_fetches(): void
    {
        $kernel = app(Kernel::class);
        $this->assertNotContains(BlockList::class, (new ReflectionProperty($kernel, 'middleware'))->getValue($kernel));
        $this->assertSame([], $this->securityTasks());
        app(SecurityResponse::class)->refresh();
        app(BlockList::class)->terminate(Request::create('/'), response('ok'));
        Http::assertNothingSent();
    }

    public static function blockedAddresses(): array
    {
        return [
            ['203.0.113.7', '203.0.113.7'],
            ['203.0.113.0/24', '::ffff:203.0.113.9'],
            ['203.0.113.0/24', '203.0.113.255'],
            ['203.0.0.0/16', '203.0.255.255'],
            ['203.0.113.7/32', '203.0.113.7'],
            ['203.0.113.128/25', '203.0.113.129'],
            ['2001:db8::7', '2001:0db8:0:0:0:0:0:7'],
            ['2001:db8::/48', '2001:db8:0:1::7'],
            ['2001:db8::/32', '2001:db8:ffff::7'],
            ['2001:db8::7/128', '2001:db8::7'],
            ['2001:db8::/49', '2001:db8:0:7fff::7'],
        ];
    }

    #[DataProvider('blockedAddresses')]
    public function test_block_runs_first_without_route_session_database_or_security_event(string $value, string $ip): void
    {
        config(['decisions.security.capture' => true]);
        $this->cacheBlocks([['value' => $value, 'expires_at' => now()->timestamp + 86400]]);
        $kernel = app(Kernel::class);
        $this->assertSame(BlockList::class, (new ReflectionProperty($kernel, 'middleware'))->getValue($kernel)[0]);
        $kernel->pushMiddleware(LaterMiddleware::class);
        $kernel->pushMiddleware(StartSession::class);
        Route::get('/blocked', function () {
            $this->fail('Route must not execute');
        });
        $database = Mockery::mock();
        $database->shouldNotReceive('connection');
        $this->app->instance('db', $database);
        LaterMiddleware::$ran = false;
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/blocked');
        $response->assertForbidden()->assertContent('Forbidden')->assertHeader('Content-Type', 'text/plain');
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse(LaterMiddleware::$ran);
        app(Recorder::class)->flush();
        Http::assertNotSent(fn ($request) => $request->method() === 'GET');
        foreach (Http::recorded() as [$request]) {
            if ($request->hasHeader('Content-Encoding', 'gzip')) {
                $types = array_column(json_decode(gzdecode($request->body()), true)['records'], 't');
                $this->assertNotContains('security', $types);
                $this->assertNotContains('request', $types);
            }
        }
    }

    public static function allowedAddresses(): array
    {
        return [
            ['203.0.113.7', '203.0.113.8'],
            ['203.0.113.0/24', '203.0.114.7'],
            ['203.0.113.128/25', '203.0.113.127'],
            ['2001:db8::/48', '2001:db8:1::7'],
            ['2001:db8::/49', '2001:db8:0:8000::7'],
            ['0.0.0.0/0', '2001:db8::7'],
            ['0.0.0.0/0', '203.0.113.7'],
            ['203.0.0.0/15', '203.0.113.7'],
            ['::/0', '2001:db8::7'],
            ['2001:db8::/31', '2001:db8::7'],
            ['2001:db8::/129', '2001:db8::7'],
            ['203.0.113.0/-1', '203.0.113.7'],
            ['invalid', '203.0.113.7'],
        ];
    }

    #[DataProvider('allowedAddresses')]
    public function test_nonmatching_and_invalid_ranges_allow(string $value, string $ip): void
    {
        $this->cacheBlocks([['value' => $value, 'expires_at' => now()->timestamp + 60]]);
        $response = app(BlockList::class)->handle(Request::create('/', server: ['REMOTE_ADDR' => $ip]), fn () => response('allowed'));
        $this->assertSame('allowed', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_expired_blocks_are_ignored(): void
    {
        $this->cacheBlocks([['value' => '127.0.0.1', 'expires_at' => now()->timestamp]]);
        Route::get('/allowed', fn () => 'allowed');
        $this->get('/allowed')->assertOk()->assertContent('allowed');
        $this->assertSame([], app(SecurityResponse::class)->blocks());
    }

    public function test_refresh_drops_invalid_and_overbroad_feed_entries_and_keeps_valid_blocks(): void
    {
        $expires = now()->timestamp + 3600;
        $valid = [
            ['value' => '203.0.0.0/16', 'expires_at' => $expires],
            ['value' => '2001:db8::/32', 'expires_at' => $expires],
            ['value' => '198.51.100.7', 'expires_at' => $expires],
        ];
        $invalid = [null, 'invalid', [], ['value' => [], 'expires_at' => $expires]];
        foreach (['0.0.0.0/0', '10.0.0.0/15', '::/0', '2001:db8::/31', 'invalid',
            '203.0.113.0/33', '2001:db8::/129', '203.0.113.0/-1', '203.0.113.0/',
            '203.0.113.0/24/1', '203.0.113.0/abc'] as $value) {
            $invalid[] = ['value' => $value, 'expires_at' => $expires];
        }
        $invalid[] = ['value' => '192.0.2.1', 'expires_at' => 'invalid'];
        $invalid[] = ['value' => '192.0.2.2'];
        $invalid[] = ['value' => '192.0.2.3', 'expires_at' => now()->timestamp];
        $this->payload['blocks'] = array_merge($invalid, $valid);
        app(SecurityResponse::class)->refresh();
        $this->assertSame($valid, app('cache')->store()->get(SecurityResponse::CACHE_KEY)['blocks']);
        $this->assertSame($valid, app(SecurityResponse::class)->blocks());
        foreach (['10.0.1.1', 'fd00::7'] as $ip) {
            $response = app(BlockList::class)->handle(Request::create('/', server: ['REMOTE_ADDR' => $ip]), fn () => response('allowed'));
            $this->assertSame('allowed', $response->getContent());
        }
    }

    public function test_spoofed_header_is_ignored_when_header_not_configured(): void
    {
        $this->cacheBlocks([['value' => '127.0.0.1', 'expires_at' => now()->timestamp + 60]]);
        $this->withHeader('do-connecting-ip', '203.0.113.9')->get('/')->assertForbidden();
    }

    public function test_configured_header_uses_first_trimmed_ip_and_invalid_value_falls_back(): void
    {
        config(['decisions.security.ip_header' => 'do-connecting-ip']);
        $this->cacheBlocks([['value' => '203.0.113.9', 'expires_at' => now()->timestamp + 60]]);
        $this->withHeader('do-connecting-ip', ' 203.0.113.9 , 198.51.100.1')->get('/')->assertForbidden();
        $this->cacheBlocks([['value' => '127.0.0.1', 'expires_at' => now()->timestamp + 60]]);
        $this->withHeader('do-connecting-ip', 'invalid, 203.0.113.9')->get('/')->assertForbidden();
    }

    public function test_cache_outage_allows_and_refresh_does_not_throw(): void
    {
        $cache = Mockery::mock();
        $cache->shouldReceive('store')->andThrow(new RuntimeException('Cache down'));
        $this->app->instance('cache', $cache);
        $response = app(BlockList::class)->handle(Request::create('/'), fn () => response('allowed'));
        $this->assertSame('allowed', $response->getContent());
        app(SecurityResponse::class)->refresh();
        Http::assertNothingSent();
    }

    public function test_refresh_uses_lock_and_server_interval_and_releases_lock(): void
    {
        $held = app('cache')->store()->lock(SecurityResponse::LOCK_KEY, 600);
        $this->assertTrue($held->get());
        app(SecurityResponse::class)->refresh();
        Http::assertNothingSent();
        $held->release();
        $this->payload['refresh'] = 120;
        $this->payload['blocks'] = [['value' => '203.0.113.7', 'expires_at' => now()->timestamp + 86400]];
        app(SecurityResponse::class)->refresh();
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'http://127.0.0.1/ingest/v1/security'
            && $request->hasHeader('Authorization', 'Bearer test-token'));
        $this->assertSame($this->payload['blocks'], app(SecurityResponse::class)->blocks());
        $this->assertTrue($held->get());
        $held->release();
        $this->travel(119)->seconds();
        app(SecurityResponse::class)->refresh();
        Http::assertSentCount(1);
        $this->travel(1)->seconds();
        app(SecurityResponse::class)->refresh();
        Http::assertSentCount(2);
    }

    public function test_middleware_fetches_only_at_termination_and_schedule_runs_every_minute(): void
    {
        $middleware = app(BlockList::class);
        $request = Request::create('/');
        $response = $middleware->handle($request, fn () => response('allowed'));
        Http::assertNothingSent();
        $middleware->terminate($request, $response);
        Http::assertSentCount(1);
        $tasks = $this->securityTasks();
        $this->assertCount(1, $tasks);
        $this->assertSame('* * * * *', $tasks[0]->expression);
        $this->travel(60)->seconds();
        $tasks[0]->run($this->app);
        Http::assertSentCount(2);
    }

    public static function failedCommands(): array
    {
        return [['logout_user', 601, 'stale'], ['disable_user', 0, 'disable_user handler not configured'], ['arbitrary_code', 0, 'unsupported']];
    }

    #[DataProvider('failedCommands')]
    public function test_commands_acknowledge_failures(string $type, int $age, string $result): void
    {
        $id = $this->queueCommand($type, $age);
        app(SecurityResponse::class)->refresh();
        $this->assertAck($id, 'failed', $result);
        $this->travel(60)->seconds();
        app(SecurityResponse::class)->refresh();
        $this->assertCount(2, Http::recorded(fn ($request) => $request->method() === 'POST'
            && $request->data() === ['status' => 'failed', 'result' => $result]));
    }

    public function test_replayed_command_resends_cached_ack_without_executing_and_result_expires_after_15_minutes(): void
    {
        $this->travelTo(now()->startOfSecond());
        ResponseHandler::$users = [];
        config([
            'decisions.security.handlers.disable_user' => ResponseHandler::class,
            'cache.default' => 'security',
            'cache.stores.security' => ['driver' => 'array'],
        ]);
        $id = $this->queueCommand('disable_user');
        app(SecurityResponse::class)->refresh();
        $this->assertAck($id, 'done', 'done');
        $key = 'decisions:security:command:'.$id;
        $result = ['status' => 'done', 'result' => 'done'];
        $this->assertSame($result, app('cache')->store('security')->get($key));
        $this->assertNull(app('cache')->store('array')->get($key));
        $this->travel(60)->seconds();
        app(SecurityResponse::class)->refresh();
        $this->assertSame(['42'], ResponseHandler::$users);
        $this->assertCount(2, Http::recorded(fn ($request) => $request->method() === 'POST'
            && $request->data() === $result));
        Http::assertSentCount(4);
        $this->travel(839)->seconds();
        $this->assertSame($result, app('cache')->store('security')->get($key));
        $this->travel(1)->seconds();
        $this->assertNull(app('cache')->store('security')->get($key));
    }

    public function test_lost_acknowledgement_is_retried_without_executing_command_again(): void
    {
        ResponseHandler::$users = [];
        config(['decisions.security.handlers.disable_user' => ResponseHandler::class]);
        $id = $this->queueCommand('disable_user');
        $acks = [];
        Http::fake(function ($request) use (&$acks) {
            if ($request->method() === 'GET') {
                return Http::response($this->payload);
            }
            $acks[] = $request->data();
            if (count($acks) === 1) {
                throw new RuntimeException('Acknowledgement lost');
            }

            return Http::response('', 202);
        });
        app(SecurityResponse::class)->refresh();
        $this->travel(60)->seconds();
        app(SecurityResponse::class)->refresh();
        $this->assertSame(['42'], ResponseHandler::$users);
        $this->assertSame([
            ['status' => 'done', 'result' => 'done'],
            ['status' => 'done', 'result' => 'done'],
        ], $acks);
        $this->assertAck($id, 'done', 'done');
    }

    public static function commandAges(): array
    {
        return [[599, 'done', ['42']], [600, 'failed', []], [601, 'failed', []]];
    }

    #[DataProvider('commandAges')]
    public function test_commands_execute_only_when_younger_than_600_seconds(int $age, string $status, array $users): void
    {
        ResponseHandler::$users = [];
        config(['decisions.security.handlers.disable_user' => ResponseHandler::class]);
        $id = $this->queueCommand('disable_user', $age);
        app(SecurityResponse::class)->refresh();
        $this->assertSame($users, ResponseHandler::$users);
        $this->assertAck($id, $status, $status === 'done' ? 'done' : 'stale');
    }

    public static function userCommands(): array
    {
        return [['logout_user'], ['disable_user']];
    }

    #[DataProvider('userCommands')]
    public function test_user_commands_revoke_only_target_users_tokens_before_handler_and_acknowledgement(string $type): void
    {
        $this->createUsers();
        app('db')->connection()->getSchemaBuilder()->create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
        });
        app('db')->table('personal_access_tokens')->insert([
            ['tokenable_type' => TokenResponseUser::class, 'tokenable_id' => 42],
            ['tokenable_type' => TokenResponseUser::class, 'tokenable_id' => 42],
            ['tokenable_type' => TokenResponseUser::class, 'tokenable_id' => 43],
        ]);
        $called = [];
        config([
            'auth.providers.users.model' => TokenResponseUser::class,
            'decisions.security.handlers.'.$type => function ($userId) use (&$called) {
                $this->assertSame([43], app('db')->table('personal_access_tokens')->pluck('tokenable_id')->all());
                $called[] = $userId;
            },
        ]);
        $id = $this->queueCommand($type);
        app(SecurityResponse::class)->refresh();
        $this->assertSame(['42'], $called);
        $this->assertSame([43], app('db')->table('personal_access_tokens')->pluck('tokenable_id')->all());
        $this->assertAck($id, 'done', 'done');
    }

    #[DataProvider('userCommands')]
    public function test_user_commands_without_tokens_relation_succeed(string $type): void
    {
        $this->createUsers();
        ResponseHandler::$users = [];
        config(['decisions.security.handlers.'.$type => ResponseHandler::class]);
        $id = $this->queueCommand($type);
        app(SecurityResponse::class)->refresh();
        $this->assertSame(['42'], ResponseHandler::$users);
        $this->assertAck($id, 'done', 'done');
    }

    #[DataProvider('userCommands')]
    public function test_token_revocation_failure_does_not_acknowledge_done(string $type): void
    {
        $this->createUsers();
        ResponseHandler::$users = [];
        config([
            'auth.providers.users.model' => TokenResponseUser::class,
            'decisions.security.handlers.'.$type => ResponseHandler::class,
        ]);
        $id = $this->queueCommand($type);
        app(SecurityResponse::class)->refresh();
        $this->assertSame([], ResponseHandler::$users);
        $this->assertAck($id, 'failed', 'command failed');
    }

    public function test_logout_deletes_only_user_database_sessions_cycles_remember_token_and_calls_handler(): void
    {
        $this->createUsers();
        app('db')->connection()->getSchemaBuilder()->create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->integer('user_id');
        });
        app('db')->table('sessions')->insert([['id' => 'one', 'user_id' => 42], ['id' => 'two', 'user_id' => 42], ['id' => 'other', 'user_id' => 43]]);
        $called = [];
        config(['session.driver' => 'database', 'decisions.security.handlers.logout_user' => function ($id) use (&$called) {
            $called[] = $id;
        }]);
        $id = $this->queueCommand('logout_user');
        app(SecurityResponse::class)->refresh();
        $this->assertSame(['other'], app('db')->table('sessions')->pluck('id')->all());
        $this->assertSame(['42'], $called);
        $this->assertNotSame('original', ResponseUser::find(42)->remember_token);
        $this->assertSame(60, strlen(ResponseUser::find(42)->remember_token));
        $this->assertAck($id, 'done', 'done');
    }

    public function test_logout_without_remember_token_column_still_calls_handler(): void
    {
        $this->createUsers(false);
        ResponseHandler::$users = [];
        config(['decisions.security.handlers.logout_user' => ResponseHandler::class]);
        $id = $this->queueCommand('logout_user');
        app(SecurityResponse::class)->refresh();
        $this->assertSame(['42'], ResponseHandler::$users);
        $this->assertAck($id, 'done', 'done');
    }

    public function test_handler_failure_is_contained_and_acknowledged_without_exporting_exception(): void
    {
        config(['decisions.security.handlers.disable_user' => fn () => throw new RuntimeException('private detail')]);
        $id = $this->queueCommand('disable_user');
        app(SecurityResponse::class)->refresh();
        $this->assertAck($id, 'failed', 'command failed');
    }

    public function test_fetch_failure_preserves_cached_blocks_and_releases_lock(): void
    {
        $blocks = [['value' => '203.0.113.7', 'expires_at' => now()->timestamp + 86400]];
        $this->cacheBlocks($blocks, now()->timestamp - 61);
        $this->fetchFails = true;
        app(SecurityResponse::class)->refresh();
        $this->assertSame($blocks, app(SecurityResponse::class)->blocks());
        $lock = app('cache')->store()->lock(SecurityResponse::LOCK_KEY, 600);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    private function cacheBlocks(array $blocks, ?int $fetched = null): void
    {
        app('cache')->store()->forever(SecurityResponse::CACHE_KEY, ['blocks' => $blocks, 'fetched_at' => $fetched ?? now()->timestamp, 'refresh' => 60]);
    }

    private function securityTasks(): array
    {
        return array_values(array_filter(app(Schedule::class)->events(), fn ($task) => $task->description === 'decisions:security'));
    }

    private function queueCommand(string $type, int $age = 0): string
    {
        $id = (string) Str::uuid();
        $this->payload['commands'] = [['id' => $id, 'type' => $type, 'user' => '42', 'issued_at' => now()->timestamp - $age]];

        return $id;
    }

    private function assertAck(string $id, string $status, string $result): void
    {
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'http://127.0.0.1/ingest/v1/security/commands/'.$id
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->data() === ['status' => $status, 'result' => $result]);
    }

    private function createUsers(bool $rememberToken = true): void
    {
        app('db')->connection()->getSchemaBuilder()->create('users', function (Blueprint $table) use ($rememberToken) {
            $table->integer('id')->primary();
            if ($rememberToken) {
                $table->rememberToken();
            }
        });
        app('db')->table('users')->insert($rememberToken ? ['id' => 42, 'remember_token' => 'original'] : ['id' => 42]);
    }
}

class LaterMiddleware
{
    public static bool $ran = false;

    public function handle($request, Closure $next)
    {
        self::$ran = true;

        return $next($request);
    }
}

class ResponseHandler
{
    public static array $users = [];

    public function __invoke(string $userId): void
    {
        self::$users[] = $userId;
    }
}

class ResponseUser extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use Authenticatable;

    protected $table = 'users';

    public $timestamps = false;
}

class TokenResponseUser extends ResponseUser
{
    public function tokens(): MorphMany
    {
        return $this->morphMany(ResponseToken::class, 'tokenable');
    }
}

class ResponseToken extends Model
{
    protected $table = 'personal_access_tokens';
}
