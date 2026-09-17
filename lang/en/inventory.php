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

    'section' => [
        'pool' => 'Stock position',
    ],

    /*
    | THE THREE NUMBERS, each with the hint that explains it. "Ten on hand, three
    | reserved, seven for sale" is the sentence this module exists to be able to
    | say, and a seller who cannot read it cannot answer "the site says sold out
    | and my shelf is not".
    */
    'search' => [
        'placeholder' => 'Product name, barcode or SKU',
    ],

    'field' => [
        'product' => 'Product',
        'variant' => 'Variant',
        'seller' => 'Seller',
        'on_hand' => 'On hand',
        'on_hand_hint' => 'The quantity you entered on the offer form.',
        'reserved' => 'Reserved',
        'reserved_hint' => 'Held for checkouts in flight.',
        'available' => 'Available',
        'available_hint' => 'On hand − reserved. This is what the storefront can sell.',
        'low_stock_threshold' => 'Low stock threshold',
        'low_stock_threshold_hint' => 'You are warned when the available quantity drops to this number. Leave it empty for no warning.',
        'no_threshold' => 'No warning',
        'updated_at' => 'Last changed',
    ],

    'filter' => [
        'low_stock' => 'Low stock',
        'out_of_stock' => 'Out of stock',
        'has_reservations' => 'Has reservations',
    ],

    'action' => [
        'set_threshold' => 'Set low stock threshold',
    ],

    'notice' => [
        'threshold_set' => 'Low stock threshold updated.',
    ],

    'empty' => [
        'heading' => 'No stock yet',
        'description' => 'Your stock appears here once you list an offer for a product.',
    ],

    'errors' => [
        'stock_is_not_edited' => 'Stock is not edited here. Change the quantity on the offer form.',
        'insufficient_stock' => 'There is not enough stock to reserve that quantity.',
        'stock_item_not_found' => 'That seller has no stock record for this variant.',
        'reservation_not_found' => 'No reservation exists under that reference.',
        'invalid_quantity' => 'A stock quantity must be a positive number.',
    ],

    /*
    | The automatic reasons written into the movement ledger — what a seller
    | reads in the history when they ask why their stock changed.
    */
    'movement' => [
        'plural' => 'Stock movements',
        'empty' => 'No stock movements yet',
        'at' => 'Date',
        'type' => 'Movement',
        'on_hand_delta' => 'On hand change',
        'reserved_delta' => 'Reserved change',
        'reference' => 'Reference',
        'note' => 'Reason',
        'mirrored_from_offer' => 'Updated from the stock entered on the offer form.',
        'offer_withdrawn' => 'Set to zero because the offer was withdrawn.',
    ],
];
