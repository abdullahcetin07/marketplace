<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reservation expiry (ADR-054, Order.md §3.3 — owner-confirmed: 30 minutes)
    |--------------------------------------------------------------------------
    |
    | How long a checkout may hold somebody else's stock before a sweep gives it
    | back. Checkout RESERVES through Inventory's command contract; the hold is
    | keyed on the order uuid, and a customer who abandons the tab never releases
    | it themselves.
    |
    | WHY THIS NUMBER EXISTS AT ALL: without it, one abandoned checkout removes a
    | unit from every other buyer's reach permanently. Inventory shipped the
    | release primitive precisely so somebody could do this; Order is the module
    | with the clock.
    |
    | THE TRADE-OFF IS SYMMETRIC AND BOTH SIDES ARE REAL. Too short and a customer
    | typing a card number watches their basket sell out under them. Too long and
    | a seller's last unit sits unbuyable while the person holding it has closed
    | the tab. 30 minutes is the owner's call and comfortably longer than any
    | honest checkout.
    |
    | It is a CONFIG rather than a constant because the right answer depends on
    | the payment provider's own timeout, which arrives with Payment — and moving
    | it must not need a release.
    |
    */

    'reservation' => [
        'expires_after_minutes' => (int) env('ORDER_RESERVATION_EXPIRY_MINUTES', 30),
    ],

    /*
    | HOW LONG A PLACED ORDER MAY GO UNPAID (ADR-072), and it is a DIFFERENT
    | window from the one above. `reservation.expires_after_minutes` is the
    | PRE-placement abandonment window — a basket somebody walked away from at
    | the address step, 30 minutes, ending in `Cancelled`. This one starts when
    | the order is PLACED and the customer is sent to the card form, and ends in
    | `Expired` with the hold released.
    |
    | FIVE MINUTES IS SHORTER THAN PayTR'S OWN IFRAME SESSION, deliberately and
    | at a cost: a customer slow at 3-D Secure can have their order expire
    | mid-payment. That is exactly why the late-payment path re-reserves or
    | refunds, and why the real value lives in `settings()` — an operator
    | lengthens it without a release if support sees churn. This is only the
    | floor for when Settings is unreachable.
    */
    'payment_window_minutes' => (int) env('ORDER_PAYMENT_WINDOW_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Order numbers (Order.md §2.3)
    |--------------------------------------------------------------------------
    |
    | The human-facing handle: what a customer quotes in an email and a seller
    | reads down the phone. DISTINCT FROM THE UUID, which is the machine
    | identifier that crosses boundaries (non-negotiable #7) — one is for people,
    | the other is for systems, and using the uuid for both would ask a customer
    | to read out 36 hex characters.
    |
    | The prefix is configurable because it ends up printed on invoices, and the
    | random suffix (rather than a sequence) means a number leaks nothing about
    | how many orders the platform has taken.
    |
    */

    'numbers' => [
        'prefix' => (string) env('ORDER_NUMBER_PREFIX', 'SP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cart limits
    |--------------------------------------------------------------------------
    |
    | Guard rails, not business rules. A cart line quantity in the thousands is
    | either a fat-fingered input or an attempt to reserve a seller's whole stock
    | for free — both are worth refusing before the reservation is attempted, and
    | availability is still the real limit underneath (Inventory decides).
    |
    */

    'cart' => [
        'max_quantity_per_line' => (int) env('ORDER_MAX_QUANTITY_PER_LINE', 100),
        'max_lines' => (int) env('ORDER_MAX_CART_LINES', 100),
    ],

];
