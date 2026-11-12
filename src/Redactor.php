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

    // $allValues masks every query value; outgoing calls can carry customer data under any name.
    public function redactUrl(string $url, bool $allValues = false): string
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
                    // A bare parameter (?jane@example.com, ?a%3Db) is itself the value.
                    if ($allValues && $key !== '') {
                        $parameter = '[redacted]';
                    }

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
                if ($allValues
                    || in_array(explode('[', $name, 2)[0], $fields, true)
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
        $message = $exception->getMessage();
        if ($exception instanceof QueryException) {
            // Driver messages can repeat raw bindings, even when the SQL itself is masked.
            $message = $exception->getSql();
            if ($bindings) {
                $message .= ' (bindings: '.json_encode($this->queryBindings($exception->getSql(), $exception->getBindings()), JSON_THROW_ON_ERROR).')';
            }
            $message .= ' (driver code: '.($exception->errorInfo[1] ?? $exception->getCode()).')';
        }

        return $this->scrubValue($message);
    }

    public function queryBindings(string $sql, array $bindings): array
    {
        $columns = config('decisions.capture.bindings_strings', $this->config['capture']['bindings_strings'] ?? 'masked') === 'scrubbed'
            ? $this->bindingColumns($sql) : null;

        foreach ($bindings as $index => &$value) {
            if (! is_string($value)) {
                continue;
            }
            $value = match (true) {
                $columns === null => is_numeric($value) ? $value : '?',
                isset($columns[$index]) && $this->sensitiveField($columns[$index]) => '[redacted]',
                (bool) preg_match('~^\$(2[abxy]|argon2i?d?)\$~', $value) => '[redacted]',
                default => $this->scrubValue($value),
            };
        }
        unset($value);

        return $bindings;
    }

    private function sensitiveField(string $name): bool
    {
        $name = strtolower($name);

        return in_array($name, $this->fields, true) || in_array($name, $this->headers, true)
            || (bool) preg_match('~pass|pwd|secret|token|key|credential|auth|signature|session|cookie|jwt|card|cvv|cvc|iban|pin|otp|ssn|^cc|_cc|pan$|account|routing|recovery|code~', $name)
            || (bool) preg_match('~nationalid|passport|idnumber|idno|idcard|identitynumber|nic|tin|taxid|drivinglicence|drivinglicense|licencenumber|licensenumber~', preg_replace('~[^a-z0-9]~', '', $name));
    }

    private function bindingColumns(string $sql): array
    {
        // Keep offsets while removing literals, comments and escaped question marks.
        $sql = preg_replace_callback('~\x27(?:\\\\.|\x27\x27|[^\x27\\\\])*\x27|\$(\w*)\$.*?\$\1\$|--[^\r\n]*|/\*.*?\*/|\?\?~s', fn ($match) => str_repeat(' ', strlen($match[0])), $sql);
        $identifier = '(?:[a-z_][a-z0-9_$]*|`[^`]+`|"[^"]+"|\[[^\]]+\])';
        $column = $identifier.'(?:\s*\.\s*'.$identifier.')*';
        $name = fn ($value) => trim(trim(substr($value, strrpos($value, '.') === false ? 0 : strrpos($value, '.') + 1)), '`"[]');
        preg_match_all('~\?~', $sql, $placeholders, PREG_OFFSET_CAPTURE);
        $indices = array_flip(array_column($placeholders[0], 1));
        $columns = [];
        preg_match_all('~('.$column.')\s*(?:=|<>|!=|<=|>=|<|>|(?:not\s+)?like)\s*(\?)~i', $sql, $comparisons, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($comparisons as $comparison) {
            $columns[$indices[$comparison[2][1]]] = $name($comparison[1][0]);
        }
        preg_match_all('~('.$column.')\s+(?:not\s+)?in\s*\(([^()]*)\)~i', $sql, $lists, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($lists as $list) {
            preg_match_all('~\?~', $list[2][0], $items, PREG_OFFSET_CAPTURE);
            foreach ($items[0] as $item) {
                $columns[$indices[$list[2][1] + $item[1]]] = $name($list[1][0]);
            }
        }
        preg_match_all('~\binsert(?:\s+or\s+\w+)?\s+into\s+'.$column.'\s*\(([^()]*)\)\s*values\s*~i', $sql, $inserts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($inserts as $insert) {
            $names = array_map($name, explode(',', $insert[1][0]));
            $depth = 0;
            $position = 0;
            for ($offset = $insert[0][1] + strlen($insert[0][0]); $offset < strlen($sql); $offset++) {
                $char = $sql[$offset];
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ',' && $depth === 1) {
                    $position++;
                } elseif ($char === '?' && $depth > 0 && isset($names[$position])) {
                    $columns[$indices[$offset]] = $names[$position];
                } elseif ($depth === 0 && $char === ',') {
                    $position = 0;
                } elseif ($depth === 0 && ! ctype_space($char)) {
                    break;
                }
            }
        }

        return $columns;
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
            '(?<uuid>\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b)',
            '(?<![a-z0-9+/_-])[a-z0-9+/_-]{32,}={0,2}(?![a-z0-9+/_-])',
            '\b(?:A\d{6}|[a-z]\d{7})\b',
            // Phone-shaped digit runs (7+ digits, single space/dash separators). Not ISO dates or digits inside identifiers.
            '(?<![\w.-])(?!\d{4}-\d{2}-\d{2}(?!\d))\+?\d(?:[ -]?\d){6,}(?![\w-]|\.\d)',
        ];

        return preg_replace_callback('~'.implode('|', $patterns).'~i', fn ($match) => ! empty($match['uuid']) ? $match['uuid'] : (isset($match['prefix']) && $match['prefix'] !== ''
            ? $match['prefix'].$match['quote'].'[REDACTED]'.$match['quote']
            : '[REDACTED]'), $value) ?? '[REDACTED]';
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

    private function redactArray(array $values, int $depth, int &$bytes, bool $log = false, bool $scrubValues = false, bool $input = false): array
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

            // User input (request bodies) can name credentials anything: new_password, client_secret, card_number.
            if (in_array($name, $this->fields, true) || in_array($name, $this->headers, true)
                || ($input && $this->sensitiveField($name))) {
                $value = '[REDACTED]';
            } else {
                $scrub = $scrubValues || in_array($name, ['context', 'source'], true);
                $nested = $input || ($depth === 0 && in_array($name, ['payload', 'body'], true));
                // Input values also get value-shape scrubbing (emails, long tokens, card and phone digit runs).
                $scrub = $scrub || $nested;
                if (is_object($value)) {
                    $value = match (true) {
                        $value instanceof JsonSerializable => $value->jsonSerialize(),
                        $value instanceof Arrayable => $value->toArray(),
                        default => ['class' => get_class($value)],
                    };
                    // Serializers can return another object, including themselves.
                    if (is_object($value)) {
                        $value = $this->redactArray(['value' => $value], $depth + 1, $bytes, false, $scrub, $nested)['value'];
                    }
                }
                if ($name === 'user' && is_array($value) && ! ($this->config['user_details'] ?? false)) {
                    $value = array_intersect_key($value, ['id' => true]);
                }
                if (is_array($value)) {
                    $value = $this->redactArray($value, $depth + 1, $bytes, false, $scrub, $nested);
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
