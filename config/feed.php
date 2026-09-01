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
                // stray sits at the root (10). The first of the two is now
                // REDUNDANT — the health root below covers it — and is kept
                // only because the doubled stray beside it is not, and a pair
                // that reads as one decision should not be split in half.
                'zayiflama-ve-diyet-urunleri',
                'zayiflama-ve-diyet-urunlerizayiflama-ve-diyet-urunleri',
                // Supplements filed under non-supplement parents (owner,
                // 2026-08-25). `outlet-besin-takviyeleri` (393 products) hangs
                // off `outlet-urunler` and `sac-bakim-vitamin-takviyeleri` (37)
                // off `sac-bakimi`. Their PARENTS stay in the feed on purpose:
                // outlet is mostly cosmetics and hair care is not a supplement
                // aisle, so excluding either branch would drop hundreds of
                // items Google is happy to take.
                'outlet-besin-takviyeleri',
                'sac-bakim-vitamin-takviyeleri',
                /*
                | **THE WHOLE HEALTH ROOT — the one place this list takes a
                | PARENT** (owner, 2026-08-27, after Merchant Center refused the
                | account's products). `saglik-ve-medikal` is 38 categories and
                | 1,127 published products of sexual health, medical devices,
                | wound care and slimming: not items Google disapproves one by
                | one, but categories Shopping does not allow at all, which is
                | how a rejection becomes a suspended account.
                |
                | Checked against the live tree before taking it — every
                | cosmetic root (`cilt-bakimi`, `gunes-kremleri`,
                | `kisisel-bakim`, `makyaj`, `sac-bakimi`, `anne-ve-bebek`) is
                | its OWN root and sits outside this subtree, so the feed keeps
                | all of them. Safe branches underneath (ear plugs and the like)
                | can return one at a time under their own review; approval
                | first.
                */
                'saglik-ve-medikal',
                /*
                | The one wound-care aisle that is NOT under the health root
                | (owner, 2026-08-27). `agiz-yarasiaft-urunleri` — 15 published
                | products of mouth-ulcer treatment — hangs off
                | `agiz-bakim-urunleri` in personal care, where the rest of the
                | shelf is toothpaste. It surfaced by reading the rebuilt feed's
                | breadcrumbs rather than the tree, which is the only way a
                | mis-filed aisle like this shows up.
                |
                | Its PARENT stays: oral care is not a medical category.
                */
                'agiz-yarasiaft-urunleri',
                /*
                | Promotional and gift items (owner, 2026-08-28). A root of its
                | own, 8 published products: samples, giveaways and bundles
                | whose titles and prices describe a promotion rather than a
                | product anyone can order on its own terms. Nothing medical
                | about it — it is simply not merchandise a Shopping listing
                | should carry.
                */
                'promosyonlar-hediye-urunler',
            ]))),
        ))),

        /*
        | Item-level safety net: titles that are medical whatever aisle they sit
        | in (owner, 2026-08-28).
        |
        | **THE CATEGORY RULES ABOVE ARE NECESSARY AND NOT SUFFICIENT.** A live
        | audit found "Lamiderm Yara ve Yanık Kremi" filed under `Cilt Bakımı >
        | Cilt Bakım Kremleri` — a burn treatment on a cosmetics shelf, which no
        | branch exclusion can reach. These words do.
        |
        | Matched FOLDED (ADR-089) and WORD-BOUNDED, with a closed set of Turkish
        | suffixes: `yanik` finds `yanık`, `termometre` finds `termometresi`, and
        | `aft` finds neither `raftabul` nor `aftershave`.
        |
        | **WHAT MUST NEVER GO IN HERE**, because it would delete the best of the
        | catalogue: `vitamin` ("Uriage Depiderm C Vitamini Serum" is prime
        | cosmetics), `krem`, `serum`, `bakım`, `leke`, `nemlendirici`,
        | `onarıcı`. The list stays narrow and unambiguous; a term that needs an
        | argument does not belong in it.
        */
        'excluded_title_keywords' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FEED_GOOGLE_EXCLUDED_TITLE_KEYWORDS', implode(',', [
                // Wound and burn treatment. The PHRASE, not the bare word:
                // "yara" alone would take "yaratıcı" and half the make-up copy.
                'yara ve yanık',
                'yanık kremi',
                'yanik kremi',
                'aft',
                // Devices. Google reads these as medical whatever the box says,
                // and a baby's bath thermometer is not worth the appeal.
                'nebulizatör',
                'tansiyon aleti',
                'termometre',
                'ortopedik',
                // Sexual health — disallowed outright on Shopping.
                'prezervatif',
                'kayganlaştırıcı',
                'geciktirici',
                'afrodizyak',
                'performans arttırıcı',
                'performans artırıcı',
                // Weight loss, which the category rules also cover; kept here
                // for the ones filed somewhere else entirely.
                'zayıflama',
                'diyet hapı',
                'medikal',
                /*
                | PET-MEDICAL, and the reason it is keywords and not a category
                | (owner, 2026-09-01). Pet Shop is 2,195 products of food, toys,
                | grooming and aquariums — real revenue Google is happy to take
                | — with no veterinary sub-category to exclude. The 15 medical
                | items are scattered through it, so the words are the only
                | handle.
                |
                | `multivitamin`, NOT `vitamin`: the bare word is how the best
                | cosmetics are named ("Uriage Depiderm C Vitamini Serum"), and
                | a rule that takes those costs more than the rejection it
                | prevents. Checked against the live catalogue before adding —
                | `multivitamin` matches 53 published products, 48 of them
                | already excluded with `besin-takviyeleri` and 5 in Pet Shop.
                | ZERO cosmetics. If one ever appears, narrow this rather than
                | widen the exception.
                |
                | `pire tasması` and `parazit` were offered and left out: they
                | match nothing in the catalogue today, and a rule that has
                | never fired is a rule nobody can say is correct.
                */
                'ameliyat boğazlığı',
                'elizabeth yakalık',
                'veteriner',
                'multivitamin',
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
