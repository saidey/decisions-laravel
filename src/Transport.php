<?php

namespace DecisionsAi\Laravel;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;
use Throwable;

class Transport
{
    public const MAX_BATCH_BYTES = 900 * 1024;

    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    private ?Factory $http = null;

    private array $config = [];

    private int $dropped = 0;

    public function __construct(array $config = [], ?Factory $http = null)
    {
        try {
            $this->config = $config;
            $this->http = $http ?? new Factory;
        } catch (Throwable) {
            $this->config = ['enabled' => false];
        }
    }

    public function send(array $batch): void
    {
        try {
            if (! ($this->config['enabled'] ?? ! empty($this->config['token'])) || empty($this->config['token'])) {
                return;
            }

            $json = json_encode($batch, self::JSON_FLAGS);
            if (strlen($json) > self::MAX_BATCH_BYTES) {
                $this->dropped += count($batch['records'] ?? []);

                return;
            }
            $body = gzencode($json);
            if ($body === false) {
                throw new RuntimeException('Unable to compress monitoring batch.');
            }

            $response = $this->request()
                ->withHeaders(['Content-Encoding' => 'gzip'])
                ->withBody($body, 'application/json')
                ->post(rtrim($this->config['url'] ?? 'https://ingest.decisions.site', '/').'/ingest/v1');

            if (! $response->successful()) {
                $this->dropped += count($batch['records'] ?? []);
            }
        } catch (Throwable) {
            $this->dropped += count(is_array($batch['records'] ?? null) ? $batch['records'] : []);
        }
    }

    public function fetchSecurity(): ?array
    {
        try {
            if (empty($this->config['security']['enforce']) || empty($this->config['token'])) {
                return null;
            }
            $response = $this->request()->get(rtrim($this->config['url'] ?? 'https://ingest.decisions.site', '/').'/ingest/v1/security');

            return $response->successful() && is_array($response->json()) ? $response->json() : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function acknowledgeSecurityCommand(string $id, string $status, string $result): void
    {
        try {
            if (empty($this->config['security']['enforce']) || empty($this->config['token'])) {
                return;
            }
            $this->request()->post(rtrim($this->config['url'] ?? 'https://ingest.decisions.site', '/').'/ingest/v1/security/commands/'.rawurlencode($id), [
                'status' => $status,
                'result' => $result,
            ]);
        } catch (Throwable) {
        }
    }

    private function request(): PendingRequest
    {
        return $this->http->connectTimeout(1)->timeout(1)->withToken($this->config['token'])->acceptJson();
    }

    public function droppedRecords(): int
    {
        try {
            return $this->dropped;
        } catch (Throwable) {
            return 0;
        }
    }
}
