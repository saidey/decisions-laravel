<?php

namespace DecisionsAi\Laravel;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SecurityResponse
{
    public const CACHE_KEY = 'decisions:security:blocks';

    public const LOCK_KEY = 'decisions:security:refresh';

    public function __construct(private Transport $transport) {}

    public function blocks(): array
    {
        try {
            $state = app('cache')->store()->get(self::CACHE_KEY, []);

            return $this->validBlocks($state['blocks'] ?? []);
        } catch (Throwable) {
            return [];
        }
    }

    public function refresh(): void
    {
        $lock = null;
        $acquired = false;
        try {
            if (! config('decisions.security.enforce', false)) {
                return;
            }
            $cache = app('cache')->store();
            if (! $this->due($cache->get(self::CACHE_KEY, []))) {
                return;
            }
            $lock = $cache->lock(self::LOCK_KEY, 60);
            if (! ($acquired = $lock->get()) || ! $this->due($cache->get(self::CACHE_KEY, []))) {
                return;
            }
            $payload = $this->transport->fetchSecurity();
            if ($payload === null || ! is_array($payload['blocks'] ?? null) || ! is_array($payload['commands'] ?? null)) {
                return;
            }
            $cache->forever(self::CACHE_KEY, [
                'blocks' => $this->validBlocks($payload['blocks']),
                'fetched_at' => now()->getTimestamp(),
                'refresh' => max(1, (int) ($payload['refresh'] ?? 60)),
            ]);
            foreach ($payload['commands'] as $command) {
                $this->command($command, $cache);
            }
        } catch (Throwable) {
            // Response actions must never interrupt the host application.
        } finally {
            if ($acquired) {
                try {
                    $lock->release();
                } catch (Throwable) {
                }
            }
        }
    }

    private function due(array $state): bool
    {
        return ($state['fetched_at'] ?? 0) + max(1, (int) ($state['refresh'] ?? 60)) <= now()->getTimestamp();
    }

    private function validBlocks(array $blocks): array
    {
        return array_values(array_filter($blocks, function ($block) {
            if (! is_array($block) || ! is_string($block['value'] ?? null)
                || ! is_numeric($block['expires_at'] ?? null) || $block['expires_at'] <= now()->getTimestamp()) {
                return false;
            }
            [$network, $prefix] = array_pad(explode('/', $block['value'], 2), 2, null);
            if (filter_var($network, FILTER_VALIDATE_IP) === false) {
                return false;
            }
            $ipv6 = str_contains($network, ':');

            return $prefix === null || (ctype_digit($prefix)
                && (int) $prefix >= ($ipv6 ? 32 : 16) && (int) $prefix <= ($ipv6 ? 128 : 32));
        }));
    }

    private function command(mixed $command, mixed $cache): void
    {
        if (! is_array($command) || ! is_string($command['id'] ?? null) || ! Str::isUuid($command['id'])) {
            return;
        }
        $id = $command['id'];
        $key = 'decisions:security:command:'.$id;
        $cached = $cache->get($key);
        if (is_array($cached)) {
            $this->transport->acknowledgeSecurityCommand($id, $cached['status'], $cached['result']);

            return;
        }
        // Reserve before side effects so retries cannot execute the same action twice.
        if (! $cache->add($key, true, 900)) {
            return;
        }
        $status = 'failed';
        $result = 'unsupported';
        try {
            if (! is_numeric($command['issued_at'] ?? null) || $command['issued_at'] <= now()->getTimestamp() - 600) {
                $result = 'stale';
            } elseif (in_array($command['type'] ?? null, ['logout_user', 'disable_user'], true)) {
                if (! is_string($command['user'] ?? null) || $command['user'] === '') {
                    throw new RuntimeException('Invalid user.');
                }
                $handler = config('decisions.security.handlers.disable_user');
                if ($command['type'] === 'disable_user' && $handler === null) {
                    $result = 'disable_user handler not configured';
                } else {
                    $this->revokeTokens($command['user']);
                    if ($command['type'] === 'logout_user') {
                        $this->logout($command['user']);
                    } else {
                        $this->handler($handler, $command['user']);
                    }
                    $status = 'done';
                    $result = 'done';
                }
            }
        } catch (Throwable) {
            $result = 'command failed';
        }
        $cache->put($key, ['status' => $status, 'result' => $result], 900);
        $this->transport->acknowledgeSecurityCommand($id, $status, $result);
    }

    private function revokeTokens(string $userId): void
    {
        $provider = config('auth.guards.'.config('auth.defaults.guard').'.provider');
        $model = config('auth.providers.'.$provider.'.model');
        if (is_string($model) && class_exists($model) && method_exists($model, 'tokens')) {
            $user = (new $model)->newQuery()->find($userId);
            $user?->tokens()->delete();
        }
    }

    private function logout(string $userId): void
    {
        try {
            if (config('session.driver') === 'database') {
                app('db')->connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $userId)->delete();
            }
            $provider = config('auth.guards.'.config('auth.defaults.guard').'.provider');
            $model = config('auth.providers.'.$provider.'.model');
            if (is_string($model) && class_exists($model)) {
                $user = (new $model)->newQuery()->find($userId);
                if ($user !== null && $user->getRememberTokenName()
                    && $user->getConnection()->getSchemaBuilder()->hasColumn($user->getTable(), $user->getRememberTokenName())) {
                    $user->setRememberToken(Str::random(60));
                    $user->save();
                }
            }
        } finally {
            $handler = config('decisions.security.handlers.logout_user');
            if ($handler !== null) {
                $this->handler($handler, $userId);
            }
        }
    }

    private function handler(mixed $handler, string $userId): void
    {
        if (is_string($handler) && class_exists($handler)) {
            $handler = app($handler);
        }
        $handler($userId);
    }
}
