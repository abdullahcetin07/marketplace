<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from these origins authenticate with the session cookie plus a
    | CSRF token (SPA mode) instead of a bearer token. The Next.js storefront
    | lives here.
    |
    | Keep this list tight: any origin listed can drive an authenticated
    | session with the user's own cookies.
    |
    */

    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', implode(',', array_filter([
        'localhost',
        'localhost:3000',
        '127.0.0.1',
        '127.0.0.1:8000',
        '::1',
        Sanctum::currentApplicationUrlWithPort(),
    ])))),

    /*
    |--------------------------------------------------------------------------
    | Guard
    |--------------------------------------------------------------------------
    |
    | Which guard Sanctum consults for a stateful (cookie) request. Customers
    | are the API's audience; admins and sellers use their panels.
    |
    */

    'guard' => ['customer'],

    /*
    |--------------------------------------------------------------------------
    | Expiration
    |--------------------------------------------------------------------------
    |
    | Minutes. Tokens expire after 7 days — an indefinitely valid token is a
    | credential that can never be revoked by the passage of time.
    |
    | Prune expired rows with: php artisan sanctum:prune-expired --hours=24
    |
    */

    'expiration' => (int) env('SANCTUM_EXPIRATION', 60 * 24 * 7),

    /*
    | Prefix that lets secret scanners (GitHub, GitLab) recognise a leaked
    | token and alert us.
    */
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'mos_'),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
