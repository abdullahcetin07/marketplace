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
        |
        | **RESEND FIRST, AND `smtp` IS GONE FROM THE CHAIN** (2026-08-24).
        |
        | This host blocks outbound SMTP: ports 25/465/587/2465/2587 time out to
        | every provider tried, with `ufw` set to ACCEPT on output and no local
        | rules — an upstream block, not something configuration can fix. Leaving
        | `smtp` in the chain meant every failover burned a full connection
        | timeout on a port that cannot open, turning a fast failure into a slow
        | one, per message.
        |
        | SES stays as the second line even though its production access was
        | REFUSED and it can currently only reach verified addresses. It costs
        | nothing while it cannot deliver, and the day the account is approved it
        | becomes a real second provider with no deploy.
        */
        'failover' => [
            'transport' => 'failover',
            'mailers' => ['resend', 'ses'],
            'retry_after' => 60,
        ],

    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@marketplaceos.test'),
        'name' => env('MAIL_FROM_NAME', 'MarketplaceOS'),
    ],

];
