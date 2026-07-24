<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Store
    |--------------------------------------------------------------------------
    |
    | Redis. It is the only store here that supports tags, which BaseService
    | relies on to scope cache invalidation to a single service — without tags
    | the only way to clear one service's entries is to flush everything.
    |
    | The test suite overrides this to `array` (see .env.testing) so tests
    | never share state through a real cache.
    |
    */

    'default' => env('CACHE_STORE', 'redis'),

    'stores' => [

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        /*
        | Fallback for a single-node deployment with no Redis. Present so the
        | app can boot degraded rather than not at all; it does not support
        | tags, so BaseService::flushCache() will fail loudly on it.
        */
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE', 'cache_locks'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Key Prefix
    |--------------------------------------------------------------------------
    |
    | Keeps this application's keys from colliding with anything else sharing
    | the Redis instance.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'marketplaceos'), '_').'_cache_'),

];
