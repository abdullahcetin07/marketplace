<?php

declare(strict_types=1);

/*
| Inventory module strings. Presentation and audit reasons only — the behaviour
| lives in the Inventory Actions.
|
| @see docs/modules/Inventory.md
*/

return [
    'singular' => 'Stock',
    'plural' => 'Stock',

    /*
    | The automatic reasons written into the movement ledger — what a seller
    | reads in the history when they ask why their stock changed.
    */
    'movement' => [
        'mirrored_from_offer' => 'Updated from the stock entered on the offer form.',
        'offer_withdrawn' => 'Set to zero because the offer was withdrawn.',
    ],
];
