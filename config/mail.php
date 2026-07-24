<?php

declare(strict_types=1);

return [

    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', 'mailpit'),
            'port' => (int) env('MAIL_PORT', 1025),
            'encryption' => env('MAIL_ENCRYPTION'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => ['transport' => 'ses'],

        'postmark' => ['transport' => 'postmark'],

        'resend' => ['transport' => 'resend'],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL', 'daily'),
        ],

        'array' => ['transport' => 'array'],

        /*
        | Tries each mailer in turn. Transactional mail on a marketplace is not
        | optional — a password reset that silently fails because one provider
        | is down is a support ticket per user.
        */
        'failover' => [
            'transport' => 'failover',
            'mailers' => ['ses', 'smtp'],
            'retry_after' => 60,
        ],

    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@marketplaceos.test'),
        'name' => env('MAIL_FROM_NAME', 'MarketplaceOS'),
    ],

];
