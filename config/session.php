<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Redis, so sessions survive a container restart and are shared across
    | every app instance behind the load balancer. The `file` driver would pin
    | each user to one container.
    |
    */

    'driver' => env('SESSION_DRIVER', 'redis'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => (bool) env('SESSION_EXPIRE_ON_CLOSE', false),

    /*
    | Session payloads contain the authenticated user id for whichever guards
    | are active. Encrypting them means a compromised Redis snapshot does not
    | hand over a set of ready-to-replay sessions.
    */
    'encrypt' => (bool) env('SESSION_ENCRYPT', true),

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION', 'session'),

    'table' => env('SESSION_TABLE', 'sessions'),

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'marketplaceos'), '_').'_session',
    ),

    'path' => env('SESSION_PATH', '/'),

    'domain' => env('SESSION_DOMAIN'),

    /*
    | Cookies are HTTPS-only outside local development. `null` would let
    | Laravel decide per-request; being explicit means a misconfigured proxy
    | cannot downgrade the cookie.
    */
    'secure' => (bool) env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),

    /*
    | The session cookie is never read by JavaScript — the Next.js frontend
    | authenticates through Sanctum, not by inspecting cookies.
    */
    'http_only' => true,

    /*
    | 'lax' allows the top-level GET navigations that OAuth and payment
    | provider callbacks rely on, while still blocking cross-site POSTs.
    | 'strict' breaks those return flows.
    */
    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    'partitioned' => false,

];
