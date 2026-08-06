<?php

declare(strict_types=1);

/*
| Payment module strings. Presentation and audit reasons only — behaviour lives
| in the Payment actions.
|
| @see docs/modules/Payment.md
*/

return [
    'singular' => 'Payment',
    'plural' => 'Payments',
    'reference' => 'Payment reference',
    'amount' => 'Amount',
    'status_label' => 'Status',
    'paid_at' => 'Paid',

    'status' => [
        'pending' => 'Pending',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'expired' => 'Expired',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partially refunded',
    ],

    'errors' => [
        'group_not_found' => 'No such checkout group.',
        'nothing_to_pay' => 'There is nothing to pay for on this checkout group.',
        'already_settled' => 'This checkout group has already been paid.',
        'gateway_unavailable' => 'The payment provider cannot be reached right now. Please try again.',
        'gateway_rejected' => 'The payment provider rejected the request.',
        'payout_amount_invalid' => 'A payout must be greater than zero.',
        'payout_exceeds_balance' => 'That is more than the seller is owed.',
        'payout_already_settled' => 'This payout has already been settled.',
        'not_refundable' => 'This payment collected nothing that could be refunded.',
        'nothing_to_refund' => 'These orders have already been refunded.',
        'return_window_closed' => 'This order cannot be returned — it has not arrived, or the window has closed.',
    ],

    'commission' => [
        'singular' => 'Commission rule',
        'plural' => 'Commission rules',
        'label' => 'Note',
        'label_hint' => 'What this row is for, readable a year from now.',
        'rate' => 'Rate',
        'rate_hint' => 'Enter a percentage: 15 → 15%.',
        'scopes' => 'Scope',
        'scopes_hint' => 'A blank field means "any". All four blank is the platform default. The rule that fills the most fields wins.',
        'seller' => 'Seller organization (UUID)',
        'category' => 'Category (UUID)',
        'category_hint' => 'A rule on a parent category covers everything beneath it.',
        'brand' => 'Brand (UUID)',
        'product' => 'Product (UUID)',
        'priority' => 'Priority',
        'priority_hint' => 'Breaks ties only between rules of EQUAL scope count; it never beats specificity.',
        'specificity' => 'Specificity',
        'is_active' => 'Active',
        'is_default' => 'Platform default',
        'never_deleted' => 'Commission rules are never deleted; deactivate them.',
    ],

    'ledger' => [
        'singular' => 'Balance entry',
        'plural' => 'Balance entries',
        'balance' => 'Balance',
        'type' => [
            'sale_credit' => 'Sale credit',
            'commission_debit' => 'Commission',
            'payout_debit' => 'Payout',
            'refund_debit' => 'Refund',
            'refund_commission_credit' => 'Commission returned',
            'payout_reversal_credit' => 'Failed payout returned',
        ],
    ],

    'payout' => [
        'singular' => 'Seller payout',
        'plural' => 'Seller payouts',
        'seller' => 'Seller organization (UUID)',
        'seller_hint' => 'Who is being paid. Their available balance appears alongside.',
        'available' => 'Payable',
        'on_hold' => 'Awaiting delivery',
        'amount' => 'Amount (TRY)',
        'amount_hint' => 'Cannot exceed the balance. The software moves NO money — a human makes the transfer.',
        'note' => 'Note',
        'note_hint' => 'e.g. "July batch".',
        'status_label' => 'Status',
        'status' => [
            'pending' => 'To send',
            'paid' => 'Sent',
            'failed' => 'Failed',
        ],
        'reference' => 'Bank reference',
        'failure_reason' => 'Rejection reason',
        'created_at' => 'Created',
        'settle' => 'Record outcome',
        'outcome' => 'What did the bank say?',
        'outcome_paid' => 'Transfer sent',
        'outcome_failed' => 'Transfer rejected (the balance is returned)',
        'detail_hint' => 'The reference the bank gave, or why it refused.',
        'settled' => 'The payout outcome was recorded.',
        'decided_by' => 'Decided by',
        'decided_by_any' => 'Any',
        'automatic' => 'Automatic',
        'manual' => 'By hand',
        'never_deleted' => 'Payouts are never deleted; mark them failed instead.',
    ],

    'payment' => [
        'never_deleted' => 'A payment is never deleted; it records what a bank did.',
    ],

    'refund' => [
        'singular' => 'Refund',
        'plural' => 'Refunds',
        'action' => 'Refund',
        'orders' => 'Orders to refund',
        'orders_hint' => 'Leave empty to refund every order in this payment.',
        'reason' => 'Why',
        'reason_hint' => 'The note that explains this refund a year from now.',
        'confirm' => 'The money goes back to the buyer through PayTR, the seller balance is debited and the stock returns. This cannot be undone.',
        'done' => 'The refund was made.',
    ],
];
