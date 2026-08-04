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
];
