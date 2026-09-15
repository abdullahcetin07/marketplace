<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Meta Conversions API (server-side Purchase)
    |--------------------------------------------------------------------------
    |
    | The browser Meta Pixel fires only after the shopper accepts marketing
    | cookies (KVKK consent), so on a consent-gated storefront it misses almost
    | every purchase — measured live: ~2,000 ad clicks, ~10 pixel page views, 0
    | purchases seen. The Conversions API sends the SAME Purchase server-side, on
    | the PayTR callback (the money source of truth), independent of the browser.
    |
    | DEDUP: the event carries `event_id = payment uuid`, the identical value the
    | browser pixel sends as `eventID` on `/odeme/sonuc`. Meta collapses the two
    | into one conversion, so a consented shopper is counted once, not twice, and
    | an un-consented one is still counted.
    |
    | INERT BY DEFAULT. `enabled` is false until an owner sets the token and turns
    | it on, so this ships dormant and cannot send anything by accident.
    |
    */

    'meta' => [
        'enabled' => (bool) env('META_CAPI_ENABLED', false),
        'pixel_id' => (string) env('META_PIXEL_ID', ''),
        'access_token' => (string) env('META_CAPI_TOKEN', ''),
        'api_version' => (string) env('META_API_VERSION', 'v21.0'),

        /*
        | Set to a code from Events Manager → Test Events to route events to the
        | test stream (not production stats) while verifying. Empty in production.
        */
        'test_event_code' => (string) env('META_CAPI_TEST_EVENT_CODE', ''),

        /*
        | Days a checkout's browser signals (IP, user agent, _fbp/_fbc) are kept.
        | They are needed only until the payment settles — minutes, or a late
        | PayTR callback — so the window is short on purpose.
        */
        'signal_retention_days' => (int) env('META_CAPI_SIGNAL_RETENTION_DAYS', 7),
    ],

];
