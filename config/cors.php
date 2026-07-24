<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | CORS
    |--------------------------------------------------------------------------
    |
    | The Next.js storefront is a separate origin, so it needs CORS. Two rules
    | that must not be relaxed:
    |
    |  1. `allowed_origins` is an explicit list, never ['*']. Sanctum's SPA
    |     mode sends credentials, and the browser refuses `*` with credentials
    |     anyway — a wildcard here silently breaks login rather than loosening
    |     it.
    |
    |  2. `supports_credentials` must stay true or the session cookie is never
    |     sent and every authenticated request 401s.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('FRONTEND_URL', 'http://localhost:3000'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'X-Request-Id',
    ],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 86400,

    'supports_credentials' => true,

];
