<?php

namespace DecisionsAi\Laravel;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\QueryException;
use JsonSerializable;
use RuntimeException;
use Throwable;

class Redactor
{
    private array $config = [];

    private array $fields = ['password', 'password_confirmation', '_token', 'token', 'secret', 'api_key'];

    private array $headers = ['authorization', 'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token'];

    public function __construct(array $config = [])
    {
        try {
            $this->config = $config;
            $this->fields = array_map('strtolower', $config['redact']['fields'] ?? $this->fields);
            $this->headers = array_map('strtolower', $config['redact']['headers'] ?? $this->headers);
        } catch (Throwable) {
            $this->config = ['invalid' => true];
        }
    }

    public function redact(array $record): array
    {
        try {
            if ($this->config['invalid'] ?? false) {
                return [];
            }

            if (($record['t'] ?? null) === 'cache' && isset($record['key'])) {
                $record['key'] = $this->redactCacheKey($record['key'], $record['store'] ?? config('cache.default'));
            }

            // Security capture is an explicit opt-in to send the client IP, so the sensor's validated ip
            // survives an app's field redaction list that names 'ip' for everything else.
            $ip = ($this->config['security']['capture'] ?? false) && in_array($record['t'] ?? null, ['security', 'request'], true)
                && is_string($record['ip'] ?? null) && filter_var($record['ip'], FILTER_VALIDATE_IP) !== false ? $record['ip'] : null;

            $bytes = 0;
            $redacted = $this->redactArray($record, 0, $bytes, ($record['t'] ?? null) === 'log');
            if ($ip !== null && $redacted !== []) {
                $redacted['ip'] = $ip;
            }

            return $redacted;
        } catch (Throwable) {
            return [];
        }
    }

    public function redactUrl(string $url): string
    {
        $url = preg_replace('~^((?:[a-z][a-z0-9+.-]*:)?//)[^/?#]*@~i', '$1', $url);
        $url = explode('#', $url, 2)[0];
        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        $authority = '';
        if (preg_match('~^((?:[a-z][a-z0-9+.-]*:)?//[^/]*)(.*)$~i', $path, $parts)) {
            [, $authority, $path] = $parts;
        }
        $segments = explode('/', $path);
        foreach ($segments as &$segment) {
            $decoded = $segment;
            do {
                $previous = $decoded;
                $decoded = rawurldecode($decoded);
            } while ($decoded !== $previous);
            if (preg_match('~[a-z0-9_-]{24,}|bot\d+:[^/]+~i', $decoded)
                || $this->scrubValue($decoded) !== $decoded) {
                $segment = '{redacted}';
            }
        }
        unset($segment);
        $path = $authority.implode('/', $segments);
        if ($query !== null) {
            $fields = [...$this->fields, 'expires', 'sig', 'code', 'state', 'otp', 'pin', 'nonce'];
            $parameters = explode('&', $query);
            foreach ($parameters as &$parameter) {
                [$key, $value] = array_pad(explode('=', $parameter, 2), 2, null);
                if ($value === null || $value === '') {
                    continue;
                }
                $name = strtolower(urldecode($key));
                do {
                    // Decode until stable, so double-encoded secrets (a%2540b.com) are still recognised.
                    $previous = $value;
                    $value = urldecode($value);
                } while ($value !== $previous);
                // Mask by name (known fields, or any name that says it is a credential) and by value shape,
                // so a secret under an unfamiliar name (?q=sk_live_…, ?to=a@b.com) is still caught. UUIDs are ids.
                $uuid = preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $value);
                if (in_array(explode('[', $name, 2)[0], $fields, true)
                    || preg_match('~password|passwd|secret|token|key|credential|auth|signature|session|cookie|jwt~', $name)
                    || (! $uuid && (preg_match('~[a-z0-9_-]{24,}~i', $value) || $this->scrubValue($value) !== $value))) {
                    $parameter = $key.'=[redacted]';
                }
            }
            $path .= '?'.implode('&', $parameters);
        }

        // Fragments never reach a server and can carry tokens (implicit-flow access_token, magic links).
        return $path;
    }

    public function exceptionMessage(Throwable $exception, bool $bindings = false): string
    {
        $message = $exception instanceof QueryException && ! $bindings
            ? $exception->getSql().' (driver code: '.($exception->errorInfo[1] ?? $exception->getCode()).')'
            : $exception->getMessage();

        return $this->scrubValue($message);
    }

    public function scrubValue(string $value): string
    {
        $url = parse_url($value);
        if (is_array($url) && isset($url['scheme'], $url['host'])) {
            $value = $this->redactUrl($value);
        }

        $fields = implode('|', array_map(fn ($field) => preg_quote($field, '~'), $this->fields));
        $sensitive = 'password|secret|token|key|credential';
        $quotedField = '(?:'.$fields.'|[^\x22\x27\r\n]*(?:'.$sensitive.')[^\x22\x27\r\n]*)';
        $variable = '(?:'.$fields.'|[a-z0-9_]*(?:'.$sensitive.')[a-z0-9_]*)';
        $patterns = [
            '(?<prefix>(?:(?:\x27'.$quotedField.'\x27|\x22'.$quotedField.'\x22)\s*=>|\$'.$variable.'\s*=(?!=|>))\s*)(?<quote>[\x22\x27])(?:\\\\.|(?!\k<quote>)[^\\\\])*\k<quote>',
            '[\x22\x27]?\b(?:password|password_confirmation|token|access_token|refresh_token|api_key|secret)\b[\s\x22\x27]*[:=]\s*+(?!\?(?:\s|[,;)]|$)|:[a-z_][a-z0-9_]*(?:\s|[,;)]|$))(?:\x22[^\x22]*\x22|\x27[^\x27]*\x27|[^\s,;&)]+)',
            '\bBearer\s+[a-z0-9._\~+/-]+=*',
            '\beyJ[a-z0-9_-]*\.[a-z0-9_-]+\.[a-z0-9_-]+\b',
            '[a-z0-9.!#$%&\x27*+/=?^_`{|}\~-]+@[a-z0-9.-]+\.[a-z]{2,}',
            '(?<![a-z0-9+/_-])[a-z0-9+/_-]{32,}={0,2}(?![a-z0-9+/_-])',
            // Phone-shaped digit runs (7+ digits, single space/dash separators). Not ISO dates or digits inside identifiers.
            '(?<![\w.-])(?!\d{4}-\d{2}-\d{2}(?!\d))\+?\d(?:[ -]?\d){6,}(?![\w-]|\.\d)',
        ];

        return preg_replace_callback('~'.implode('|', $patterns).'~i', fn ($match) => isset($match['prefix']) && $match['prefix'] !== ''
            ? $match['prefix'].$match['quote'].'[REDACTED]'.$match['quote']
            : '[REDACTED]', $value) ?? '[REDACTED]';
    }

    private function redactCacheKey(string $key, ?string $store): string
    {
        $sessionStore = config('session.store');
        if (! $sessionStore && in_array(config('session.driver'), ['apc', 'memcached', 'redis', 'dynamodb'], true)) {
            $sessionStore = config('session.driver');
        }
        $session = ($sessionStore !== null && $store === $sessionStore) || preg_match('/^[a-z0-9]{40}$/iD', $key);
        foreach (['session.cookie', 'session.prefix'] as $option) {
            $prefix = config($option);
            $session = $session || (is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix));
        }
        if (($this->config['capture']['cache_keys'] ?? false) && ! $session) {
            return $this->scrubValue($key);
        }

        $salt = (string) config('app.key');
        if ($salt === '') {
            return '[REDACTED]';
        }
        $prefix = ! $session && str_contains($key, ':') ? explode(':', $key, 2)[0] : '';
        if ($this->scrubValue($prefix) !== $prefix) {
            $prefix = '';
        }

        return ($prefix !== '' ? $prefix.':' : '').substr(hash_hmac('sha256', $key, $salt), 0, 12);
    }

    private function redactArray(array $values, int $depth, int &$bytes, bool $log = false, bool $scrubValues = false): array
    {
        if ($depth > 10) {
            throw new RuntimeException('Monitoring record exceeds the redaction depth limit.');
        }

        $redacted = [];
        foreach ($values as $key => $value) {
            $name = strtolower((string) $key);

            if ((! ($this->config['capture']['payloads'] ?? false) && in_array($name, ['body', 'payload'], true))
                || (! ($this->config['capture']['bindings'] ?? false) && $name === 'bindings')
                || (! ($this->config['capture']['source'] ?? false) && $name === 'source')
                || (! ($this->config['user_details'] ?? false) && in_array($name, ['user_name', 'user_email'], true))) {
                continue;
            }

            if (in_array($name, $this->fields, true) || in_array($name, $this->headers, true)) {
                $value = '[REDACTED]';
            } else {
                $scrub = $scrubValues || in_array($name, ['context', 'source'], true);
                if (is_object($value)) {
                    $value = match (true) {
                        $value instanceof JsonSerializable => $value->jsonSerialize(),
                        $value instanceof Arrayable => $value->toArray(),
                        default => ['class' => get_class($value)],
                    };
                    // Serializers can return another object, including themselves.
                    if (is_object($value)) {
                        $value = $this->redactArray(['value' => $value], $depth + 1, $bytes, false, $scrub)['value'];
                    }
                }
                if ($name === 'user' && is_array($value) && ! ($this->config['user_details'] ?? false)) {
                    $value = array_intersect_key($value, ['id' => true]);
                }
                if (is_array($value)) {
                    $value = $this->redactArray($value, $depth + 1, $bytes, false, $scrub);
                } elseif (is_string($value)) {
                    if ($name === 'message' || $scrub) {
                        $value = $this->scrubValue($value);
                    }
                    $value = mb_strcut($value, 0, $log && $depth === 0 && $name === 'message' ? 16384 : 8192, 'UTF-8');
                }
            }
            if ($name === 'context' && strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 32768) {
                $value = ['truncated' => true];
            }
            // Bound the temporary normalized tree before encoding the final record.
            $bytes += strlen((string) $key) + (is_string($value) ? strlen($value) : 1);
            if ($bytes > 131072) {
                throw new RuntimeException('Monitoring record exceeds the normalization budget.');
            }
            $redacted[$key] = $value;
        }

        return $redacted;
    }
}
