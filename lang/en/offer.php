<?php

declare(strict_types=1);

/*
| Offer module strings. Presentation and audit reasons only — the behaviour
| lives in the Offer Actions.
|
| @see docs/modules/Offer.md
*/

return [
    'singular' => 'Offer',
    'plural' => 'Offers',

    /*
    | The audit reason recorded for automatic, product-lifecycle transitions
    | (§3.5) — what a seller finds in the trail when they ask why their listing
    | stopped selling.
    */
    'cascade' => [
        'product_archived' => 'Paused automatically because the product was removed from the catalog.',
        'product_republished' => 'Resumed automatically because the product was published again.',
    ],
];
