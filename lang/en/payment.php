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
];
