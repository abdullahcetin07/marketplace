<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | Redis, supervised by Horizon. The `sync` connection is used in tests so
    | assertions run against completed work rather than a queued placeholder.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'queue'),
            'queue' => env('REDIS_QUEUE', 'default'),

            /*
            | Seconds before an unacknowledged job is considered lost and made
            | available again. MUST exceed the longest job timeout (BaseJob
            | sets 120s) or a slow job will be picked up a second time while
            | the first attempt is still running.
            */
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 180),

            'block_for' => null,
            'after_commit' => true,
        ],

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 180),
            'after_commit' => true,
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Jobs
    |--------------------------------------------------------------------------
    |
    | Failed jobs go to PostgreSQL, not Redis. A Redis eviction or flush would
    | silently destroy the record of what failed — which is precisely the data
    | you need after an incident.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

];
