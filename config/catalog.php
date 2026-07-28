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

];
