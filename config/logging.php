<?php

declare(strict_types=1);

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | Four distinct concerns, deliberately kept in separate files rather than
    | one firehose:
    |
    |   daily     — general application log, 30-day rotation
    |   audit     — who did what to which record; 1-year retention because it
    |               answers "was this price changed by the seller or by us?",
    |               which is a dispute-resolution question, not a debugging one
    |   activity  — mirror of the Activity module's user timeline
    |   errors    — errors only, so an on-call engineer greps one small file
    |               instead of paging through debug noise
    |
    | Retention differs per channel because their purposes differ. Merging them
    | would force the shortest retention on the most legally significant data.
    |
    | @see docs/logging.md
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'deprecations'),
        'trace' => (bool) env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    | **WHERE THE FILES GO — AND WHY THIS IS A VARIABLE.** The suite ran against
    | the same paths as the server, so `php artisan test` wrote its own noise into
    | the real files: one day's audit log held 12,735 `testing.` lines against 16
    | genuine ones. A trail that exists to be EVIDENCE cannot be 99% test output —
    | the signal is not lost exactly, it is unfindable, which is the same thing at
    | the moment somebody needs it.
    |
    | Empty by default, so every production path is byte-identical to what it was.
    | `phpunit.xml` sets it to `testing/`.
    */
    'directory' => env('LOG_DIRECTORY', ''),

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'daily,errors')),
            'ignore_exceptions' => false,
        ],

        /*
        | General application log. JSON-free, human-readable, rotated daily.
        */
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/'.env('LOG_DIRECTORY', '').'marketplaceos.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => (int) env('LOG_DAILY_DAYS', 30),
            'replace_placeholders' => true,
        ],

        /*
        | Business-significant actions. Written to by BaseService::log(),
        | BaseObserver and the domain event subscriber.
        */
        'audit' => [
            'driver' => 'daily',
            'path' => storage_path('logs/'.env('LOG_DIRECTORY', '').'audit.log'),
            'level' => 'info',
            'days' => (int) env('LOG_AUDIT_DAYS', 365),
            'permission' => 0640,
            'replace_placeholders' => true,
        ],

        /*
        | The Activity module writes to `activity_entries`; this channel
        | receives a mirrored stream so a user's security history survives a
        | database restore-to-point-in-time.
        | @see App\Modules\Activity\Application\Services\ActivityLogger
        */
        'activity' => [
            'driver' => 'daily',
            'path' => storage_path('logs/'.env('LOG_DIRECTORY', '').'activity.log'),
            'level' => 'info',
            'days' => (int) env('LOG_ACTIVITY_DAYS', 180),
            'replace_placeholders' => true,
        ],

        /*
        | Errors only. This is the file to tail during an incident.
        */
        'errors' => [
            'driver' => 'daily',
            'path' => storage_path('logs/'.env('LOG_DIRECTORY', '').'errors.log'),
            'level' => 'error',
            'days' => (int) env('LOG_ERROR_DAYS', 90),
            'replace_placeholders' => true,
        ],

        /*
        | Container/Kubernetes: everything to stdout for the log collector.
        | Selected by setting LOG_CHANNEL=stderr in production.
        */
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/'.env('LOG_DIRECTORY', '').'laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => SyslogUdpHandler::class,
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'deprecations' => [
            'driver' => 'daily',
            'path' => storage_path('logs/'.env('LOG_DIRECTORY', '').'deprecations.log'),
            'level' => 'warning',
            'days' => 14,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/'.env('LOG_DIRECTORY', '').'emergency.log'),
        ],

    ],

];
