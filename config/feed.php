<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Google Merchant Center product feed
    |--------------------------------------------------------------------------
    |
    | The nightly RSS 2.0 file Google fetches for free Shopping listings and
    | Shopping/PMax ads. Single-merchant: the platform is the merchant of record
    | (ADR-060), so the feed carries the BUY BOX winner's price and says nothing
    | about which seller won it.
    |
    */

    'google' => [

        /*
        | The public site a feed row links to. It is NOT `app.url`: Laravel serves
        | the API and the panels, while the storefront is a separate Next.js
        | application on its own origin (ADR-025/058), and a `link` pointing at
        | the API would send every shopper Google sends us to a JSON 404.
        */
        'storefront_url' => rtrim((string) env('FEED_GOOGLE_STOREFRONT_URL', 'https://raftabul.com'), '/'),

        /*
        | Optional shared secret. Google's scheduled fetch accepts a query string,
        | so `?key=…` is the whole mechanism. Empty means public — which is the
        | honest default, because every field in the feed is already readable on
        | the storefront and a token here protects nothing but the crawl budget.
        */
        'access_token' => (string) env('FEED_GOOGLE_ACCESS_TOKEN', ''),

        /*
        | Category slugs to keep out, with their descendants.
        |
        | Google restricts parts of the health and supplement space, and a feed
        | that keeps submitting a disapproved category is a feed with a standing
        | policy strike. **Supplements are excluded as of 2026-08-24** (owner's
        | decision): `besin-takviyeleri` and everything under it — 2,409
        | published products, vitamins, minerals, omega and the rest.
        |
        | **THE FOUR SLUGS AFTER IT ARE NOT A SECOND DECISION, THEY ARE THE SAME
        | ONE.** The catalogue has a handful of ROOT categories whose slug is its
        | own name repeated — `d3-k2-vitaminid3-k2-vitamini` (79 products),
        | `magnezyum-bisglisinatmagnezyum-bisglisinat` (44),
        | `antioksidan-iceren-e-vitaminleriantioksidan-iceren-e-vitaminleri` (9),
        | `takviye-edici-gida-urunleri` (2). They are supplement categories that
        | landed at the TOP of the tree instead of under `besin-takviyeleri`, so
        | excluding the parent branch alone would have left D3-K2 and magnesium —
        | the two the owner named — in the feed. Fixing the tree is a catalogue
        | job; until it happens, the policy has to name where the products
        | actually are.
        |
        | The env var REPLACES this list rather than adding to it, so anything
        | set there must repeat what is still wanted.
        */
        'excluded_category_slugs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FEED_GOOGLE_EXCLUDED_CATEGORY_SLUGS', implode(',', [
                'besin-takviyeleri',
                'd3-k2-vitaminid3-k2-vitamini',
                'magnezyum-bisglisinatmagnezyum-bisglisinat',
                'antioksidan-iceren-e-vitaminleriantioksidan-iceren-e-vitaminleri',
                'takviye-edici-gida-urunleri',
                // Weight loss, restricted by Google in its own right (owner,
                // 2026-08-24). TWO entries for one idea again: the real branch
                // sits under `saglik-ve-medikal` (16 products) and the doubled
                // stray sits at the root (10). Naming the branch rather than
                // the health root is deliberate — medical devices and the rest
                // of `saglik-ve-medikal` stay in the feed.
                'zayiflama-ve-diyet-urunleri',
                'zayiflama-ve-diyet-urunlerizayiflama-ve-diyet-urunleri',
            ]))),
        ))),

        /*
        | Below this many characters a description is treated as absent and the
        | item is dropped rather than submitted.
        |
        | GMC rejects empty and near-empty descriptions, and a rejected item costs
        | more than a missing one: it counts against the account. The build report
        | prints how many rows this removed, which is what tells the owner how
        | much of the catalogue still needs Turkish copy written for it.
        */
        'min_description_length' => (int) env('FEED_GOOGLE_MIN_DESCRIPTION_LENGTH', 30),

        /*
        | Where the built file lands. Served by `GET /feed/google-merchant.xml`.
        */
        'path' => 'feeds/google-merchant.xml',

        /*
        | Rows held in memory before being flushed to disk. The catalogue is ~20k
        | products and the whole point of building to a file is that neither this
        | process nor Google's fetch ever holds all of it at once.
        */
        'chunk_size' => (int) env('FEED_GOOGLE_CHUNK_SIZE', 500),
    ],

];
