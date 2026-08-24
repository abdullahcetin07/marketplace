<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third-Party Services
    |--------------------------------------------------------------------------
    |
    | Credentials for external services. Sprint 0 registers only cross-cutting
    | infrastructure; payment providers, shipping carriers and marketplace
    | integrations are added by the modules that consume them.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'eu-central-1'),
    ],

    /*
    | TWO ENV NAMES, ON PURPOSE. `resend/resend-laravel` reads its own
    | `config('resend.api_key')` — which is `RESEND_API_KEY` — and falls back to
    | this key, which Laravel documents as `RESEND_KEY`. Accepting both means an
    | owner who types the name from either set of docs gets a working mailer
    | instead of "The Resend API key is missing" at three in the morning.
    */
    'resend' => [
        'key' => env('RESEND_KEY', env('RESEND_API_KEY')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Tracking
    |--------------------------------------------------------------------------
    |
    | Sentry is wired through config only — the SDK is installed per
    | environment so it never runs in the test suite. An empty DSN disables
    | reporting entirely, which is the intended local default.
    |
    | @see docs/logging.md §"Error tracking"
    |
    */

    'sentry' => [
        'dsn' => env('SENTRY_LARAVEL_DSN'),
        'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1),
        'environment' => env('APP_ENV', 'production'),
        'release' => env('APP_RELEASE'),
        'send_default_pii' => false,
    ],

];
