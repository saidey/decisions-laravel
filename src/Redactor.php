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

            $bytes = 0;

            return $this->redactArray($record, 0, $bytes, ($record['t'] ?? null) === 'log');
        } catch (Throwable) {
            return [];
        }
    }

    public function redactUrl(string $url): string
    {
        $url = preg_replace('~^((?:[a-z][a-z0-9+.-]*:)?//)[^/?#]*@~i', '$1', $url);
        [$url, $fragment] = array_pad(explode('#', $url, 2), 2, null);
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
            $fields = [...$this->fields, 'key', 'signature', 'sig', 'access_token'];
            $parameters = explode('&', $query);
            foreach ($parameters as &$parameter) {
                $key = explode('=', $parameter, 2)[0];
                $name = strtolower(explode('[', urldecode($key), 2)[0]);
                if (in_array($name, $fields, true)) {
                    $parameter = $key.'=[redacted]';
                }
            }
            $path .= '?'.implode('&', $parameters);
        }

        return $path.($fragment !== null ? '#'.$fragment : '');
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
        return preg_replace([
            '~[\x22\x27]?\b(?:password|password_confirmation|token|access_token|refresh_token|api_key|secret)\b[\s\x22\x27]*[:=]\s*+(?!\?(?:\s|[,;)]|$)|:[a-z_][a-z0-9_]*(?:\s|[,;)]|$))(?:\x22[^\x22]*\x22|\x27[^\x27]*\x27|[^\s,;&)]+)~i',
            '~\bBearer\s+[a-z0-9._\~+/-]+=*~i',
            '~\beyJ[a-z0-9_-]*\.[a-z0-9_-]+\.[a-z0-9_-]+\b~i',
            '~[a-z0-9.!#$%&\x27*+/=?^_`{|}\~-]+@[a-z0-9.-]+\.[a-z]{2,}~i',
            '~(?<![a-z0-9+/_-])[a-z0-9+/_-]{32,}={0,2}(?![a-z0-9+/_-])~i',
        ], '[REDACTED]', $value) ?? '[REDACTED]';
    }

    private function redactArray(array $values, int $depth, int &$bytes, bool $log = false, bool $source = false): array
    {
        if ($depth > 10) {
            throw new RuntimeException('Monitoring record exceeds the redaction depth limit.');
        }

        $redacted = [];
        foreach ($values as $key => $value) {
            $name = strtolower((string) $key);

            if ((! ($this->config['capture']['payloads'] ?? false) && in_array($name, ['body', 'payload'], true))
                || (! ($this->config['capture']['bindings'] ?? false) && $name === 'bindings')
                || (! ($this->config['capture']['source'] ?? true) && $name === 'source')
                || (! ($this->config['user_details'] ?? false) && in_array($name, ['user_name', 'user_email'], true))) {
                continue;
            }

            if (in_array($name, $this->fields, true) || in_array($name, $this->headers, true)) {
                $value = '[REDACTED]';
            } else {
                if (is_object($value)) {
                    $value = match (true) {
                        $value instanceof JsonSerializable => $value->jsonSerialize(),
                        $value instanceof Arrayable => $value->toArray(),
                        default => ['class' => get_class($value)],
                    };
                    // Serializers can return another object, including themselves.
                    if (is_object($value)) {
                        $value = $this->redactArray(['value' => $value], $depth + 1, $bytes)['value'];
                    }
                }
                if ($name === 'user' && is_array($value) && ! ($this->config['user_details'] ?? false)) {
                    $value = array_intersect_key($value, ['id' => true]);
                }
                if (is_array($value)) {
                    $value = $this->redactArray($value, $depth + 1, $bytes, false, $source || $name === 'source');
                } elseif (is_string($value)) {
                    if ($name === 'message' || $source) {
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
