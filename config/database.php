<?php

declare(strict_types=1);

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        /*
        |----------------------------------------------------------------------
        | PostgreSQL — the production database
        |----------------------------------------------------------------------
        |
        | Chosen over MySQL for three things this platform actually needs:
        | JSONB with GIN indexes (product attributes, SEO metadata), proper
        | partial and expression indexes, and transactional DDL so a failed
        | migration leaves no half-applied schema.
        |
        */

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'postgres'),
            'port' => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'marketplaceos'),
            'username' => env('DB_USERNAME', 'marketplaceos'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        |----------------------------------------------------------------------
        | Read replica
        |----------------------------------------------------------------------
        |
        | Same credentials, different host. Laravel routes SELECTs here and
        | everything else to the writer, and automatically keeps a connection
        | on the writer for the remainder of a request that has written —
        | which is what stops read-after-write from returning stale rows.
        |
        | Enabled by setting DB_READ_HOST; otherwise identical to `pgsql`.
        |
        */

        'pgsql_read' => [
            'driver' => 'pgsql',
            'read' => [
                'host' => array_filter(explode(',', (string) env('DB_READ_HOST', (string) env('DB_HOST', 'postgres')))),
            ],
            'write' => [
                'host' => [env('DB_HOST', 'postgres')],
            ],
            'sticky' => true,
            'port' => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'marketplaceos'),
            'username' => env('DB_USERNAME', 'marketplaceos'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        |----------------------------------------------------------------------
        | SQLite — test suite only
        |----------------------------------------------------------------------
        |
        | In-memory, so the unit and feature suites are hermetic and can run in
        | parallel. Tests that depend on PostgreSQL-specific behaviour (JSONB
        | operators, full-text search) must target `pgsql` explicitly and be
        | tagged as integration tests. @see docs/testing.md
        |
        */

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    |
    | Four logical databases on one instance so a cache flush cannot wipe the
    | queue, and so `KEYS` debugging on one concern does not scan the others:
    |
    |   db 0  default      — locks, ad-hoc
    |   db 1  cache        — application cache (flushable)
    |   db 2  queue        — Horizon's queues and job payloads
    |   db 3  session      — user sessions
    |
    | Never point cache and queue at the same database: `cache:clear` would
    | then delete pending jobs.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', 'marketplaceos_'),
            'persistent' => (bool) env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', 'redis'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_DB', 0),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', 'redis'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_CACHE_DB', 1),
        ],

        'queue' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', 'redis'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_QUEUE_DB', 2),
        ],

        'session' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', 'redis'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_SESSION_DB', 3),
        ],

    ],

];
