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

    'field' => [
        'product' => 'Product',
        'product_hint' => 'Search the published catalog. If the product does not exist yet, open it first.',
        'variant' => 'Variant',
        'variant_hint' => 'The exact variant you sell (colour, size…). Price and stock belong to it.',
        'store' => 'Store',
        'store_hint' => 'The storefront this offer appears under. Only your active stores are listed.',
        'seller' => 'Seller',
        'price' => 'Price',
        'price_hint' => 'What the buyer pays, VAT included.',
        'list_price' => 'List price',
        'list_price_hint' => 'Optional. Shown struck through; it cannot be lower than the selling price.',
        'stock' => 'Stock',
        'stock_hint' => 'How many you have. Enter 0 to mark it sold out — your price and your place are kept.',
        'status' => 'Status',
        'listed_at' => 'Listed',
        'suspended_at' => 'Suspended',
        'status_before' => 'Previous status',
        'reason' => 'Reason',
        'reason_hint' => 'Recorded in the audit trail.',
        'suspend_reason_hint' => 'Required. Recorded in the audit trail and shown as the reason.',
    ],

    'section' => [
        'listing' => 'Offer',
        'suspension' => 'Suspension record',
    ],

    'create' => [
        'what' => 'What are you selling?',
        'what_hint' => 'The product is chosen from the shared catalog — you never create your own copy.',
        'terms' => 'Price and stock',
    ],

    'action' => [
        'create' => 'Pick from the catalog & sell',
        'pause' => 'Pause',
        'pause_confirm' => 'The offer stops selling but is not deleted; your price and your place are kept.',
        'resume' => 'Resume',
        'withdraw' => 'Withdraw',
        'withdraw_confirm' => 'The offer is removed for good. You can list the same variant again later.',
        'suspend' => 'Suspend',
        'suspend_confirm' => 'The offer is pulled everywhere. The seller cannot lift this — only an admin can.',
        'reinstate' => 'Lift suspension',
        'reinstate_confirm' => 'The offer returns to the state it was in before the suspension — not automatically live.',
    ],

    'notice' => [
        'paused' => 'Offer paused.',
        'resumed' => 'Offer is live again.',
        'withdrawn' => 'Offer withdrawn.',
        'suspended' => 'Offer suspended.',
        'reinstated' => 'Suspension lifted.',
    ],

    'empty' => [
        'heading' => 'You have no offers yet',
        'description' => 'Pick a product from the catalog, set a price and stock, and your offer goes live immediately.',
    ],

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
