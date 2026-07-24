<?php

declare(strict_types=1);

return [

    'name' => env('APP_NAME', 'MarketplaceOS'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Europe/Istanbul (UTC+3, no DST since 2016).
    |
    | IMPORTANT: this affects PHP's date functions and how Carbon renders
    | timestamps — it does NOT change how they are stored. All timestamps are
    | persisted in UTC (`timestamptz` on PostgreSQL) and converted for display.
    | Storing local time would make every cross-border order report wrong.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'Europe/Istanbul'),

    /*
    |--------------------------------------------------------------------------
    | Localisation
    |--------------------------------------------------------------------------
    |
    | Turkish is the primary locale; English is the fallback used whenever a
    | translation key is missing, and is the locale the public API answers in
    | unless a client sends Accept-Language.
    |
    */

    /*
    | Plain ISO codes, never a Language model: Language is a LOOKUP TABLE owned
    | by the Localization module, and config is loaded before the database is
    | reachable and must survive `config:cache`. The same env keys back
    | `marketplace.localization.default_language`, so the two agree by default.
    */
    'locale' => env('APP_LOCALE', 'tr'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'tr_TR'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | Used for sessions, signed URLs and the encrypted casts on 2FA secrets.
    | Rotating it invalidates every encrypted column — see docs/security.md
    | before you do.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => array_filter(
        explode(',', (string) env('APP_PREVIOUS_KEYS', '')),
    ),

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode
    |--------------------------------------------------------------------------
    |
    | Redis-backed so `artisan down` takes effect across every container at
    | once. The `file` driver would only take down whichever pod ran it.
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'redis'),
    ],

];
