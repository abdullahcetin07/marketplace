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

    /*
    | Cancellation reasons. `expired` is the automatic one: written by the sweep
    | that gives stock back when a customer closes the tab (§3.3).
    */
    'cancel' => [
        'expired' => 'The checkout timed out and the reserved stock was released.',
    ],
];
