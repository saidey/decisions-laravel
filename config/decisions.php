<?php

return [
    'enabled' => env('DECISIONS_ENABLED', (bool) env('DECISIONS_TOKEN')),
    'token' => env('DECISIONS_TOKEN'),
    'url' => env('DECISIONS_URL', 'https://ingest.decisions.site'),
    'environment' => env('DECISIONS_ENVIRONMENT', config('app.env', 'production')),
    'deploy' => env('DECISIONS_DEPLOY'),
    'user_details' => env('DECISIONS_USER_DETAILS', false),

    'sample' => [
        'request' => 1.0,
        'job' => 1.0,
        'command' => 1.0,
        'task' => 1.0,
        'query' => 1.0,
        'cache' => 1.0,
        'mail' => 1.0,
        'notification' => 1.0,
        'http' => 1.0,
        'log' => 1.0,
        'exception' => 1.0,
        'schedule' => 1.0,
    ],

    'ignore' => [
        'paths' => [],
        'commands' => [],
        'jobs' => [],
        'exceptions' => [],
    ],

    'buffer' => 1000,

    'redact' => [
        'fields' => ['password', 'password_confirmation', '_token', 'token', 'secret', 'api_key'],
        'headers' => ['authorization', 'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token'],
    ],

    'capture' => [
        'payloads' => false,
        'bindings' => false,
        'source' => env('DECISIONS_CAPTURE_SOURCE', false),
    ],
];
