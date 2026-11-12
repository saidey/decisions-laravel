<?php

namespace DecisionsAi\Laravel\Sensors;

use DecisionsAi\Laravel\DecisionsServiceProvider;
use DecisionsAi\Laravel\Recorder;
use DecisionsAi\Laravel\Redactor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Laravel\Sanctum\Sanctum;
use ReflectionProperty;
use Throwable;

class Security
{
    private const AUTH_EVENTS = [
        Login::class => 'login',
        Failed::class => 'login_failed',
        Lockout::class => 'lockout',
        Logout::class => 'logout',
        PasswordReset::class => 'password_reset',
        Verified::class => 'verified',
        'Laravel\Fortify\Events\TwoFactorAuthenticationEnabled' => 'two_factor_enabled',
        'Laravel\Fortify\Events\TwoFactorAuthenticationDisabled' => 'two_factor_disabled',
    ];

    private ?int $lastPosture = null;

    public function __construct(private Recorder $recorder, private Users $users, private Redactor $redactor) {}

    public function register(): void
    {
        try {
            if (! config('decisions.security.capture', false)) {
                return;
            }
            foreach (self::AUTH_EVENTS as $event => $kind) {
                app('events')->listen($event, fn ($event) => $this->auth($event, $kind));
            }
            $this->listenToModels(config('decisions.security.watch_models', []), ['created', 'updated', 'deleted'], false);
            // Eloquent fires events per class, so follow the app's own token model, set during boot.
            app()->booted(fn () => $this->listenToModels(config('decisions.security.token_models')
                ?? (class_exists('Laravel\\Sanctum\\Sanctum') ? [Sanctum::$personalAccessTokenModel] : []), ['created', 'deleted'], true));
            foreach ([CommandStarting::class, JobProcessing::class] as $event) {
                app('events')->listen($event, fn () => $this->posture());
            }
        } catch (Throwable) {
        }
    }

    private function listenToModels(array $models, array $actions, bool $token): void
    {
        foreach ($models as $model) {
            foreach ($actions as $action) {
                app('events')->listen('eloquent.'.$action.': '.$model, fn ($model) => $this->model($model, $action, $token));
            }
        }
    }

    public function context(?Request $request = null): array
    {
        $request ??= app()->bound('request') ? app('request') : null;

        return [
            'ip' => $request === null ? null : self::clientIp($request),
            'ua' => $request?->userAgent() === null ? null : mb_strcut($this->redactor->scrubValue($request->userAgent()), 0, 255, 'UTF-8'),
        ];
    }

    /**
     * The visitor's IP: the first valid address from the headers named in
     * security.ip_header (comma-separated, tried in order), else $request->ip().
     * Only name headers your edge overwrites, or a client can choose its own IP.
     */
    public static function clientIp(Request $request): ?string
    {
        foreach (array_filter(array_map('trim', explode(',', (string) config('decisions.security.ip_header')))) as $header) {
            $candidate = trim(explode(',', (string) $request->headers->get($header))[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return $request->ip();
    }

    public function auth(object $event, string $kind): void
    {
        try {
            if (! config('decisions.security.capture', false)) {
                return;
            }
            $this->posture();
            $fields = [];
            if ($event instanceof Login || $event instanceof Failed || $event instanceof Logout || $event instanceof Lockout) {
                $fields['guard'] = $event->guard ?? config('auth.defaults.guard');
            }
            if ($event instanceof Failed) {
                foreach (['email', 'username', 'name', 'phone'] as $key) {
                    if (array_key_exists($key, $event->credentials)) {
                        if (is_string($event->credentials[$key])) {
                            $fields['identifier'] = $this->hash(strtolower(trim($event->credentials[$key])));
                        }
                        break;
                    }
                }
            }
            $this->record($kind, $fields, $event->request ?? null, $event->user ?? null);
        } catch (Throwable) {
        }
    }

    public function requestHandled(RequestHandled $event): void
    {
        try {
            if (! config('decisions.security.capture', false)) {
                return;
            }
            $this->posture();
            $request = $event->request;
            $kind = match ($event->response->getStatusCode()) {
                403 => 'forbidden',
                419 => 'csrf',
                429 => 'rate_limited',
                401 => 'unauthenticated',
                404 => $this->probe($request->getPathInfo()) ? 'probe' : null,
                default => null,
            };
            if ($kind === null) {
                return;
            }
            $route = $request->route();
            $fields = ['route' => $route?->getName() ?? $this->redactor->redactUrl($route ? '/'.ltrim($route->uri(), '/') : $request->getPathInfo())];
            if ($kind === 'probe') {
                $fields['path'] = $this->redactor->redactUrl($request->getPathInfo());
            }
            $key = $request->attributes->get('throttle_key');
            if ($kind === 'rate_limited' && is_string($key)) {
                $fields['key'] = $this->hash($key);
            }
            $this->record($kind, $fields, $request);
        } catch (Throwable) {
        }
    }

    public function model(Model $model, string $action, bool $token): void
    {
        try {
            if (! config('decisions.security.capture', false)) {
                return;
            }
            $this->posture();
            $fields = $token ? [
                'tokenable_id' => $model->getAttribute('tokenable_id'),
                'tokenable_type' => $model->getAttribute('tokenable_type'),
                'name' => $this->redactor->scrubValue((string) $model->getAttribute('name')),
                'abilities' => array_map(fn ($ability) => $this->redactor->scrubValue((string) $ability), (array) $model->getAttribute('abilities')),
            ] : [
                'actor' => $this->users->identity()['user'],
                'subject' => ['class' => get_class($model), 'key' => $model->getKey()],
                'action' => $action,
                'changed' => array_keys($action === 'updated' ? $model->getChanges() : $model->getAttributes()),
            ];
            $this->record($token ? 'token_'.$action : 'privileged', $fields);
        } catch (Throwable) {
        }
    }

    public function posture(): void
    {
        try {
            $now = now()->getTimestamp();
            if (! config('decisions.security.capture', false) || ($this->lastPosture !== null && $this->lastPosture > $now - 3600)) {
                return;
            }
            $this->lastPosture = $now;
            try {
                $key = 'decisions:security:posture:'.hash('sha256', gethostname().':'.getmypid().':'.base_path().':'.config('decisions.token'));
                if (! app('cache')->store()->add($key, true, 3600)) {
                    return;
                }
            } catch (Throwable) {
                // Cache outages must not hide posture; the process-local timestamp still limits it.
            }
            $https = str_starts_with(strtolower((string) config('app.url')), 'https://');
            try {
                $url = app('url');
                $https = $https || (new ReflectionProperty($url, 'forceScheme'))->getValue($url) === 'https://';
            } catch (Throwable) {
            }
            // ponytail: composer audit advisories count is deferred.
            $this->record('posture', [
                'app' => ['debug' => config('app.debug'), 'env' => config('app.env')],
                'session' => ['secure' => config('session.secure')],
                'https_forced' => $https,
                'client_version' => DecisionsServiceProvider::VERSION,
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ]);
        } catch (Throwable) {
        }
    }

    private function probe(string $path): bool
    {
        foreach (config('decisions.security.probes', []) as $probe) {
            if (is_string($probe) && $probe !== '' && stripos($path, $probe) !== false) {
                return true;
            }
        }

        return false;
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('decisions.token'));
    }

    private function record(string $kind, array $fields, ?Request $request = null, ?Authenticatable $user = null): void
    {
        $at = microtime(true);
        $this->recorder->record([
            't' => 'security',
            'kind' => $kind,
            'ts' => $at,
            'at' => $at,
            ...$this->context($request),
            ...$this->users->identity(user: $user),
            ...$fields,
        ]);
    }
}
