<?php

declare(strict_types=1);

/*
| Order module strings. Presentation and record-keeping reasons only — the
| behaviour lives in the Order Actions.
|
| @see docs/modules/Order.md
*/

return [
    'singular' => 'Order',
    'plural' => 'Orders',
    'lines' => 'Order lines',

    'section' => [
        'summary' => 'Order summary',
        'shipping' => 'Shipping and billing address',
        'cancellation' => 'Cancellation',
    ],

    'field' => [
        'number' => 'Order number',
        'status' => 'Status',
        'placed_at' => 'Date',
        'line_count' => 'Lines',
        'items_total' => 'Items total',
        'tax_total' => 'KDV',
        /*
        | Prices INCLUDE KDV (ADR-042). Without this note a seller adds the two
        | numbers together and sees more than the customer paid.
        */
        'tax_total_hint' => 'Already inside the items total, not added to it.',
        'grand_total' => 'Grand total',
        'billing_address' => 'Billing address',
        'cancelled_at' => 'Cancelled at',
        'reason' => 'Reason',
        'customer' => 'Customer',
        'seller' => 'Seller',
        'checkout_group' => 'Checkout group',
        'checkout_group_hint' => 'Paste this into the search to find every order from the same basket.',
    ],

    'line' => [
        'product' => 'Product',
        'quantity' => 'Quantity',
        'unit_price' => 'Unit price',
        'tax_rate' => 'KDV rate',
        'tax' => 'KDV amount',
        'total' => 'Line total',
    ],

    'action' => [
        'cancel' => 'Cancel order',
        'cancel_confirm' => 'The order will be cancelled and the reserved stock released. This cannot be undone.',
        'cancel_reason_hint' => 'Shown to the customer. Write something short and clear, like "out of stock".',
    ],

    'notice' => [
        'cancelled' => 'Order cancelled.',
    ],

    'empty' => [
        'heading' => 'No orders yet',
        'description' => 'When a customer buys one of your offers, their order appears here.',
    ],

    'errors' => [
        'already_cancelled' => 'This order has already been cancelled.',
        'not_cancellable' => 'This order can no longer be cancelled.',
    ],

    /*
    | Cancellation reasons. `expired` is the automatic one: written by the sweep
    | that gives stock back when a customer closes the tab (§3.3).
    */
    'cancel' => [
        'expired' => 'The checkout timed out and the reserved stock was released.',
    ],
];
