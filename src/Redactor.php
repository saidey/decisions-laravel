<?php

namespace DecisionsAi\Laravel;

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

            return $this->redactArray($record);
        } catch (Throwable) {
            return [];
        }
    }

    private function redactArray(array $values, int $depth = 0): array
    {
        if ($depth > 64) {
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
                $redacted[$key] = '[REDACTED]';
            } elseif ($name === 'user' && is_array($value) && ! ($this->config['user_details'] ?? false)) {
                $redacted[$key] = array_intersect_key($value, ['id' => true]);
            } else {
                $redacted[$key] = is_array($value) ? $this->redactArray($value, $depth + 1) : $value;
            }
        }

        return $redacted;
    }
}
