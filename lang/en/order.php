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
        'cancelled_by' => 'Cancelled by',
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
        /*
        | THE SELLER'S WARNING (ADR-057). Cancelling does not only stop the order:
        | because the seller is saying they cannot fulfil, their stock for that
        | variant is ZEROED. The confirmation says so, rather than leaving it to be
        | discovered afterwards.
        */
        'cancel_confirm_seller' => 'The order will be cancelled and YOUR STOCK for this product will be set to zero — it stays off sale until you enter stock again. This cannot be undone.',
        'cancel_confirm_button' => 'Cancel and zero my stock',
        'cancel_reason_hint' => 'Shown to the customer. Write something short and clear, like "out of stock".',
        'zero_seller_stock' => 'Seller fault: zero their stock too',
        'zero_seller_stock_hint' => 'Tick this when the seller genuinely has none of this product. By default only the reservation is released and the product stays on sale.',
    ],

    /*
    | Who cancelled (ADR-057). Four different business events end the same way, and
    | the seller's notification, the fraud signal and the abandonment metric all
    | have to tell them apart.
    */
    'cancelled_by' => [
        'customer' => 'Customer',
        'seller' => 'Seller',
        'admin' => 'Admin',
        'expiry' => 'Timed out',
    ],

    'notice' => [
        'cancelled' => 'Order cancelled.',
        'stock_zeroed' => 'Your stock for this product has been set to zero. Enter a new quantity on the offer form to sell it again.',
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
