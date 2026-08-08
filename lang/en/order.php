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

    'cancellation' => [
        'singular' => 'Cancellation request',
        'plural' => 'Cancellation requests',
        'requested_at' => 'Requested',
        'buyer_reason' => "Buyer's reason",
        'approve' => 'Approve',
        'approve_confirm' => 'The whole order is refunded to the buyer, the commission is reversed, the stock returns to you and the shipment is closed. This cannot be undone.',
        'approve_button' => 'Approve and refund',
        'approved_notice' => 'Cancellation approved',
        'approved_body' => 'The buyer has been refunded and the stock is back.',
        'reject' => 'Reject',
        'reject_button' => 'Reject',
        'rejected_notice' => 'Request rejected',
        'decision_reason' => 'Reason for refusing',
        'decision_reason_hint' => 'The buyer will be shown this.',
        'empty' => 'No cancellation requests waiting.',
    ],

    'return' => [
        'singular' => 'Return request',
        'plural' => 'Return requests',
        'requested_at' => 'Requested at',
        'buyer_reason' => "Buyer's reason",
        'units' => 'Units',
        'code' => 'Return code',
        'code_hint' => 'The buyer will send the item back with this code. Use the return code your carrier gave you.',
        'cargo' => 'Carrier',
        'approve' => 'Approve',
        'approve_hint' => 'Approving is NOT a refund — it tells the buyer how to send the item back. The refund happens when it reaches you, via "Complete return".',
        'approve_button' => 'Approve and send the return code',
        'approved_notice' => 'Return approved',
        'approved_body' => 'The buyer has the return code. Press "Complete return" once the item reaches you.',
        'reject' => 'Reject',
        'reject_button' => 'Reject',
        'rejected_notice' => 'Request rejected',
        'decision_reason' => 'Reason for rejection',
        'decision_reason_hint' => 'The buyer will see this.',
        'complete' => 'Complete return',
        'complete_confirm' => 'You are confirming you have received the item. The selected units are REFUNDED to the buyer, the commission is reversed and the stock returns to you. This cannot be undone.',
        'complete_button' => 'Received — start the refund',
        'completed_notice' => 'Return completed',
        'completed_body' => 'The buyer has been refunded and the stock is back.',
        'complete_failed' => 'The return could not be completed',
        'empty' => 'No return requests waiting.',
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
        'cancel_lines' => 'Cannot fulfil',
        'cancel_lines_confirm' => 'The units you choose are refunded to the buyer, the commission is reversed and the stock returns to you. This is impossible once the parcel is with a carrier.',
        'cancel_lines_button' => 'Cancel and refund',
        'cancel_lines_remaining' => 'Remaining: :count',
        'cancel_lines_reason_hint' => 'The buyer will be shown this.',
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
        'lines_cancelled' => 'Lines cancelled',
        'lines_cancelled_body' => 'The buyer has been refunded and the stock is back.',
        'nothing_cancelled' => 'No quantity was chosen.',
    ],

    'empty' => [
        'heading' => 'No orders yet',
        'description' => 'When a customer buys one of your offers, their order appears here.',
    ],

    'errors' => [
        'already_cancelled' => 'This order has already been cancelled.',
        'paid_needs_refund' => 'A paid order is cancelled by refunding it, not by this action.',
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
