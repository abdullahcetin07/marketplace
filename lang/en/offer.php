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

    'tokens' => [
        'title' => 'API keys',
        'subheading' => 'Create a key so your own system can push price and stock in bulk.',
        'create' => 'New key',
        'name' => 'Key name',
        'name_hint' => 'Which system will use it? e.g. "ERP", "stock robot".',
        'created' => 'Key created — copy it now, it will not be shown again:',
        'revoke' => 'Revoke',
        'revoked' => 'Key revoked',
        'existing' => 'Existing keys',
        'empty' => 'You have not created a key yet.',
        'last_used' => 'Last used',
        'never_used' => 'never used',
        'how_heading' => 'How to use it',
        'note' => [
            'once' => 'A key is shown ONCE; only its hash is stored. If you lose it, create a new one and revoke the old.',
            'scope' => 'The key acts as you and can only write your OWN store\'s offers.',
            'endpoints' => 'Endpoints: /sync (price+stock), /stock (stock only), /withdraw (take off sale).',
        ],
    ],

    'feed' => [
        'batch_too_large' => 'At most :max items per call; split the list.',
        'import' => 'Bulk upload via CSV',
        'completed' => ':imported offers processed, :failed rows failed.',
        'column' => [
            'gtin' => 'Barcode (GTIN)',
            'price' => 'Price',
            'stock' => 'Stock',
            'list_price' => 'List price',
        ],
    ],

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
        // The number you type REPLACES the stock, it is not added to it (ADR-048).
        'stock_hint_live' => 'Sellable right now: :available. What you type here replaces your stock — it is not added to it.',
        'available' => 'Sellable',
        'declared' => 'Declared',
        'status' => 'Status',
        'listed_at' => 'Listed',
        'buy_box_rank' => 'Buy-box rank',
        'buy_box_price' => 'Buy-box price',
        'suspended_at' => 'Suspended',
        'status_before' => 'Previous status',
        'reason' => 'Reason',
        'reason_hint' => 'Recorded in the audit trail.',
        'suspend_reason_hint' => 'Required. Recorded in the audit trail and shown as the reason.',
    ],

    /*
    | ADR-057 — the audit reason written when a seller cancels an order they
    | cannot fulfil and their stock is zeroed. Without it the seller's own record
    | shows an edit nobody made.
    */
    'stock' => [
        'zeroed_by_seller_cancellation' => 'Order :order was cancelled as unfulfillable; stock for this product was set to zero.',
        // What the live column reads at 0 — a state, not a number.
        'sold_out' => 'Sold out',
    ],

    /*
    | The buy box is computed on every read, never stored (ADR-045). These are
    | the two things a seller opens this page for: where am I, and what do I
    | have to beat.
    */
    'buy_box' => [
        'rank_of' => ':rank of :total',
        'you_are_winning' => 'You have the buy box',
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
