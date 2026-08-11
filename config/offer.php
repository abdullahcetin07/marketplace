<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Seller offer feed (ADR-076)
    |--------------------------------------------------------------------------
    |
    | The feed is SYNCHRONOUS: a seller's POST is answered with a per-item result
    | rather than a job id, because a system pushing prices needs to know which
    | ones landed before it moves on. That only works if a single call cannot tie
    | up a worker for minutes, which is what the batch ceiling is for — a
    | four-thousand-item POST is refused with 422 and split by the caller, not
    | swallowed and slowly chewed.
    |
    | 500 is a size a seller's nightly job can page through comfortably and a
    | request can finish inside an ordinary PHP timeout.
    |
    */
    'feed' => [
        'max_batch' => (int) env('OFFER_FEED_MAX_BATCH', 500),
    ],
];
