<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The gateway (ADR-060, Payment.md §3)
    |--------------------------------------------------------------------------
    |
    | One PSP, named here rather than hard-wired, because the domain talks only
    | to `PaymentGatewayContract` and a second provider is a second adapter. The
    | key is the container binding's discriminator, not a class name — a class
    | name in config is a refactor that breaks a deploy.
    |
    */

    'gateway' => env('PAYMENT_GATEWAY', 'paytr'),

    /*
    |--------------------------------------------------------------------------
    | PayTR
    |--------------------------------------------------------------------------
    |
    | CREDENTIALS COME FROM THE ENVIRONMENT AND ARE NEVER COMMITTED. `merchant_key`
    | and `merchant_salt` are what make the callback hash unforgeable: anyone
    | holding them can post a "payment succeeded" this platform would believe.
    |
    | `test_mode` DEFAULTS TO ON, deliberately. The failure of forgetting to flip
    | it in production is a payment that does not take money; the failure of
    | forgetting to flip it in development is a payment that does. Only one of
    | those is recoverable.
    |
    */

    'paytr' => [
        'merchant_id' => env('PAYTR_MERCHANT_ID', ''),
        'merchant_key' => env('PAYTR_MERCHANT_KEY', ''),
        'merchant_salt' => env('PAYTR_MERCHANT_SALT', ''),

        'test_mode' => (bool) env('PAYTR_TEST_MODE', true),

        // PayTR's own endpoint. In config because a sandbox host is a deploy-time
        // fact, not a code change.
        'token_url' => env('PAYTR_TOKEN_URL', 'https://www.paytr.com/odeme/api/get-token'),
        'refund_url' => env('PAYTR_REFUND_URL', 'https://www.paytr.com/odeme/iade'),

        /*
        | WHERE PAYTR MUST BE TOLD TO POST THE RESULT — and the one setting this
        | file cannot actually apply. The iFrame API has NO notification-URL
        | parameter (`merchant_ok_url`/`merchant_fail_url` only decide where the
        | BROWSER lands); the callback address is configured in
        | **PayTR Mağaza Paneli → Destek & Kurulum → Ayarlar → Bildirim URL**.
        |
        | IT IS RECORDED HERE ANYWAY, because a value that lives only in a third
        | party's web form is a value nobody can review, diff or check against a
        | deploy. `payment:diagnose` prints it; the deploy run-book tells an
        | operator to paste it into that form.
        |
        | GETTING IT WRONG IS SILENT ON OUR SIDE: the money is taken, the buyer
        | sees the success page, and the order stays `awaiting_payment` because
        | the callback — not the redirect — is what settles it (Payment.md §3).
        */
        'notification_url' => env('PAYTR_NOTIFICATION_URL', env('APP_URL').'/api/v1/payments/paytr/callback'),

        /*
        | How long PayTR keeps the iframe session alive, in minutes. The buyer's
        | stock stays RESERVED for this long on a payment nobody completes, so it
        | is a stock decision as much as a payment one — kept in step with
        | `order.reservation.expires_after_minutes` rather than guessed.
        */
        'timeout_minutes' => (int) env('PAYTR_TIMEOUT_MINUTES', 30),

        /*
        | Installments pass through to the buyer (Payment.md §11): PayTR shows
        | whatever the merchant account allows, and v1 does NOT model the
        | vade-farkı split. `no_installment => true` would force single payment.
        */
        'no_installment' => (bool) env('PAYTR_NO_INSTALLMENT', false),
        'max_installment' => (int) env('PAYTR_MAX_INSTALLMENT', 0), // 0 = the account's limit
    ],

    /*
    |--------------------------------------------------------------------------
    | Where PayTR sends the buyer's browser back to
    |--------------------------------------------------------------------------
    |
    | THE REDIRECT IS NOT THE SOURCE OF TRUTH — the server-to-server callback is
    | (Payment.md §3). These only decide what the buyer SEES; a shopper who closes
    | the tab after paying still gets their order, because the callback already
    | confirmed it. Storefront routes, so they live on the app URL.
    |
    */

    'result_urls' => [
        'success' => env('PAYMENT_SUCCESS_URL', env('APP_URL').'/odeme/sonuc'),
        'failure' => env('PAYMENT_FAILURE_URL', env('APP_URL').'/odeme/hata'),
    ],

];
