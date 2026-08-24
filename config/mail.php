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
        /*
        | `retry_after` IS A BLAST RADIUS, NOT A BACKOFF (2026-08-24).
        |
        | Symfony's round-robin marks a transport that threw as dead for this
        | many seconds and will not try it again in that window. So the number
        | is not "how long before we retry the provider" — it is "how long one
        | bad message keeps the working provider switched off for EVERYBODY".
        |
        | Observed in production: a single `@example.com` recipient left over
        | from storefront testing made Resend throw `Invalid 'to' field`, SES
        | (still sandboxed) returned 403 behind it, and the next two real
        | notifications in the same burst died with "No transports found"
        | without either provider being asked. Sixty seconds of a marketplace's
        | order and password mail, lost to one fake address.
        |
        | Five seconds keeps the point of the round-robin — a provider having a
        | genuine outage is not hammered once per message — while making the
        | window too short to swallow a burst. The real defence is upstream:
        | `blocked_recipient_domains` below stops the poisoned message from ever
        | reaching a transport.
        */
        'failover' => [
            'transport' => 'failover',
            'mailers' => ['resend', 'ses'],
            'retry_after' => 5,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Recipient domains that are never mailed
    |--------------------------------------------------------------------------
    |
    | Addresses on these domains are dropped before they reach a transport, by
    | `App\Core\Infrastructure\Mail\BlockedRecipientGuard`.
    |
    | These are the reserved and disposable domains that CANNOT receive mail —
    | `example.*` is reserved by RFC 2606 and Resend rejects it outright. A
    | provider rejection is not a harmless failure here: it is what kills the
    | failover chain for everyone else (see `retry_after` above). Dropping the
    | message costs nothing, because there was never anybody at the other end.
    |
    | The same list decides what `users:purge-test-accounts` treats as a test
    | account — one definition of "not a real address", used by both the guard
    | that protects delivery and the cleanup that removes the trigger. Adding a
    | REAL domain here therefore does two things, not one.
    |
    | Empty (`MAIL_BLOCKED_RECIPIENT_DOMAINS=`) disables the guard entirely.
    |
    */
    'blocked_recipient_domains' => array_values(array_filter(array_map(
        static fn (string $domain): string => mb_strtolower(trim($domain)),
        explode(',', (string) env(
            'MAIL_BLOCKED_RECIPIENT_DOMAINS',
            'example.com,example.org,example.net,test.com,mailinator.com',
        )),
    ))),

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@marketplaceos.test'),
        'name' => env('MAIL_FROM_NAME', 'MarketplaceOS'),
    ],

];
