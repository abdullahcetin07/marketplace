<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Catalog Locales (Catalog.md §13.5)
    |--------------------------------------------------------------------------
    |
    | Catalog display strings — category and attribute labels, product title and
    | description — are carried in PER-LOCALE COLUMNS from the start rather than
    | retrofitted later. These are the locales those columns exist for.
    |
    | This is deliberately NOT the `languages` lookup table. Languages are an
    | operator concern (enable Arabic without a deploy); a catalog locale is a
    | SCHEMA concern — adding one is a migration, so it belongs in code. The
    | first entry is the authoring locale a form defaults to.
    |
    */

    'locales' => ['tr', 'en'],

    /*
    |--------------------------------------------------------------------------
    | Variant Generation (ADR-039, Catalog.md §13.4)
    |--------------------------------------------------------------------------
    |
    | Variants are generated as the cartesian product of the variant-defining
    | attribute values a seller selects, then pruned. Cartesian growth is
    | multiplicative — five axes of five values is 3,125 rows from one form
    | submission — so the generator refuses above this cap rather than letting a
    | plausible-looking selection write a table's worth of SKUs.
    |
    */

    'variants' => [
        'max_generated' => (int) env('CATALOG_MAX_GENERATED_VARIANTS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate Suggestion (Catalog.md §13.2)
    |--------------------------------------------------------------------------
    |
    | When a seller authors a product we SUGGEST likely existing matches so they
    | pick one instead of creating a duplicate. Auto-merge is rejected outright
    | (silently fusing two products is unrecoverable), so this only bounds how
    | many candidates the authoring UI offers.
    |
    */

    'duplicates' => [
        'suggestion_limit' => (int) env('CATALOG_DUPLICATE_SUGGESTIONS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slug Registry (ADR-059)
    |--------------------------------------------------------------------------
    |
    | The storefront addresses product, category and brand at the ROOT —
    | `/bioderma`, `/cilt-bakimi`, `/avene-...-krem` — with no type prefix. That
    | is an aesthetic choice (a prefix is SEO-neutral; Google ignores it), and
    | its real cost is a SHARED NAMESPACE: those three plus the storefront's own
    | pages all live at `/`, so a slug must be unique across every one of them.
    |
    | THE RESERVED LIST IS THE OTHER HALF OF THAT COST. A product called "Sepet"
    | would slug to `sepet` and shadow the basket page — the catch-all route
    | never sees it, so the product simply becomes unreachable and the basket
    | keeps working, which is the kind of bug nobody reports because nobody can
    | describe it. The registry refuses these outright and suffixes instead.
    |
    | IT MUST MATCH THE STOREFRONT'S STATIC ROUTES, and that direction matters:
    | a NEW app route has to be added HERE FIRST, before the frontend ships it,
    | or a product may already be sitting on it. There is no way for the backend
    | to discover the frontend's routes, so this list is the contract between the
    | two and the only place either side should look.
    |
    */

    'slugs' => [

        'reserved' => [
            // Storefront pages (see `storefront/src/app/`).
            'sepet', 'hesap', 'odeme', 'giris', 'giris-yap', 'kayit', 'cikis',
            'urunler', 'urun', 'magaza', 'kategori', 'marka', 'arama',

            // Laravel + infrastructure prefixes nginx routes away from Next
            // (see `docs/storefront-deploy.md`).
            'api', 'admin', 'seller', 'store', 'sanctum', 'livewire', 'build',
            'storage', 'vendor', 'horizon', 'telescope',

            // Files and conventions a crawler or the framework expects at the
            // root; a product living at `/sitemap.xml` would be worse than
            // unreachable.
            'sitemap', 'sitemap.xml', 'robots.txt', 'favicon.ico', '_next',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Search engine settings (ADR-090)
    |--------------------------------------------------------------------------
    |
    | Pushed to Meilisearch by `search:sync-settings`, which is idempotent and
    | safe to re-run on every deploy.
    |
    | **THIS FILE IS THE OWNER OF THE SYNONYMS IN v1**, deliberately: they are
    | version-controlled, reviewable and deployed like code, and an admin UI for
    | them is a v2 job. The cost is that adding one takes a deploy.
    |
    */
    'search' => [

        /*
        | Ranked in priority order — a match in the title outranks a match in
        | the description, whatever else is equal. NOTHING FROM OFFER: no
        | price, no stock, no seller (ADR-037, `CatalogBoundaryTest`).
        */
        'searchable_attributes' => ['title', 'title_en', 'brand', 'category', 'skus', 'gtin', 'description'],

        /*
        | Facets. `category_path` is a prefix filter — "everything under Giyim"
        | is the same shape the database uses. Price is NOT here and cannot be:
        | one product has as many prices as it has sellers.
        */
        'filterable_attributes' => ['category_path', 'brand', 'status', 'is_sellable'],

        /*
        | `is_sellable` is Catalog's own denormalised buyability flag (ADR-079),
        | which is what lets the ranking lift buyable products without Offer
        | data crossing the boundary.
        */
        'sortable_attributes' => ['is_sellable', 'published_at'],

        /*
        | Meilisearch's defaults, then ONE custom rule: a product nobody can buy
        | sinks below one that can. The work order also asked for a sales-count
        | rule; that number lives in Order and Catalog holds no synced copy of
        | it, so it is deliberately NOT built here — see the ADR's follow-ups.
        */
        'ranking_rules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'is_sellable:desc'],

        /*
        | Meilisearch will not return past this however large a `limit` is
        | asked for, so it is the real ceiling on `ranked_limit` below.
        */
        'pagination' => ['maxTotalHits' => 1000],

        /*
        | One edit from five characters, two from nine. Shorter than five is
        | left exact on purpose: at three or four letters almost everything is
        | one edit from everything else, and "krem" would find "kram" and
        | "krom" as confidently as itself.
        */
        'typo_tolerance' => [
            'enabled' => true,
            'minWordSizeForTypos' => ['oneTypo' => 5, 'twoTypos' => 9],
        ],

        /*
        | Two-way synonyms. Each line is written in both directions because
        | Meilisearch's synonyms are directional, and a shopper may type either
        | side. Turkish spellings of foreign brand names belong here — that is
        | the class of miss the fold cannot fix, because `uriaj` is not a
        | diacritic variant of `uriage`, it is a different word.
        */
        'synonyms' => [
            'güneş kremi' => ['güneş koruyucu', 'spf', 'güneş bakım'],
            'güneş koruyucu' => ['güneş kremi', 'spf'],
            'spf' => ['güneş kremi', 'güneş koruyucu'],
            'nemlendirici' => ['nemlendirme', 'moisturizer'],
            'nemlendirme' => ['nemlendirici'],
            'uriaj' => ['uriage'],
            'uriage' => ['uriaj'],
            'aven' => ['avène', 'avene'],
            'vitamin c' => ['c vitamini', 'askorbik asit'],
            'c vitamini' => ['vitamin c'],
            'saç dökülmesi' => ['dökülme karşıtı', 'saç bakım'],
            'leke' => ['leke karşıtı', 'aydınlatıcı'],
        ],

        /*
        | How deep the ranked set goes before the listing filters it.
        |
        | MEASURED, not guessed: at 500 the live `gunes` query came back with
        | 243 results where the fold had 343, because Meilisearch holds 782 hits
        | for it and the sellable filter then bit into a truncated set. A
        | shopper cannot tell a cut total from a real one. A thousand is twenty
        | pages of a 48-row listing and is Meilisearch's own `maxTotalHits`
        | default; a query as broad as `krem` (2,434 hits) is still cut.
        */
        'ranked_limit' => 1000,
    ],

];
