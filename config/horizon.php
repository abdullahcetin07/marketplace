<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'admin/horizon'),

    'use' => 'default',

    /*
    | Namespaces Horizon's own Redis keys. Must differ between environments
    | sharing a Redis instance, or staging metrics appear in production.
    */
    'prefix' => env('HORIZON_PREFIX', 'horizon:'.Str::slug((string) env('APP_NAME', 'mos'), '_').':'),

    /*
    | Horizon's dashboard is gated by the `system.horizon.view` permission —
    | see App\Providers\HorizonServiceProvider. `auth` alone is not enough:
    | the admin panel has non-super-admin roles that must not see job payloads,
    | which routinely contain customer data.
    */
    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
        'redis:notifications' => 60,
        'redis:search' => 120,
        'redis:media' => 300,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,   // 7 days
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [
        //
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    /*
    | Hard ceiling on worker memory in MB. A worker exceeding it is restarted,
    | which is the cheapest defence against a slow leak in a long-lived
    | process.
    */
    'memory_limit' => 128,

    /*
    |--------------------------------------------------------------------------
    | Queue Workers
    |--------------------------------------------------------------------------
    |
    | Four queues with different characteristics, deliberately separated so one
    | cannot starve another:
    |
    |   notifications — short, latency-sensitive (password resets, order mail).
    |                   Highest priority; a customer is waiting on it.
    |   default       — general domain work.
    |   search        — OpenSearch indexing. Bursty (a bulk import enqueues
    |                   thousands) and nobody is blocked on it, so it gets its
    |                   own workers rather than clogging `default`.
    |   media         — image conversions. Slow and CPU-heavy; long timeout,
    |                   few workers, so it cannot monopolise the box.
    |
    | A single shared queue would let one bulk product import delay every
    | password reset email on the platform.
    |
    */

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['notifications', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 0,
        ],

        'supervisor-search' => [
            'connection' => 'redis',
            'queue' => ['search'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 192,
            'tries' => 3,
            'timeout' => 180,
            'nice' => 5,
        ],

        'supervisor-media' => [
            'connection' => 'redis',
            'queue' => ['media'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 500,
            'memory' => 512,
            'tries' => 2,
            'timeout' => 600,
            'nice' => 10,
        ],
    ],

    'environments' => [

        'production' => [
            'supervisor-default' => [
                'minProcesses' => 2,
                'maxProcesses' => 20,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
            'supervisor-search' => [
                'minProcesses' => 1,
                'maxProcesses' => 8,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
            'supervisor-media' => [
                'minProcesses' => 1,
                'maxProcesses' => 4,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 10,
            ],
        ],

        'staging' => [
            'supervisor-default' => ['maxProcesses' => 4],
            'supervisor-search' => ['maxProcesses' => 2],
            'supervisor-media' => ['maxProcesses' => 1],
        ],

        'local' => [
            'supervisor-default' => [
                'maxProcesses' => 3,
                'balance' => 'simple',
            ],
            'supervisor-search' => ['maxProcesses' => 1],
            'supervisor-media' => ['maxProcesses' => 1],
        ],

    ],

];
