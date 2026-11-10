<?php

namespace DecisionsAi\Laravel;

use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

class Transport
{
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

            $body = gzencode(json_encode($batch, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            if ($body === false) {
                throw new RuntimeException('Unable to compress monitoring batch.');
            }

            $response = $this->http
                ->connectTimeout(1)
                ->timeout(1)
                ->withToken($this->config['token'])
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

    public function droppedRecords(): int
    {
        try {
            return $this->dropped;
        } catch (Throwable) {
            return 0;
        }
    }
}
