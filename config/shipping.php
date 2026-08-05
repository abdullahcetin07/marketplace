<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The delivery windows (ADR-064, Shipping.md §3, §7)
    |--------------------------------------------------------------------------
    |
    | THESE ARE FALLBACKS, NOT THE SOURCE OF TRUTH. The module reads each of them
    | through `settings()`, so an operator tunes a window without a release — the
    | platform's own "who owns the value" test, and these three are owned by
    | whoever answers the support tickets, not by whoever deploys.
    |
    | The values here are what `settings()` returns when the settings table is
    | unreachable (it never breaks boot, by design — CLAUDE.md). A shipping module
    | that stopped inferring deliveries because a settings row was missing would
    | stop paying sellers, which is a worse failure than an out-of-date default.
    |
    | S1 WRITES NONE OF THEM. `transit_days` is what the S2 sweep measures against
    | `shipped_at`; `payout_hold_days` and `return_days` are what S3 starts from
    | `delivered_at`. They are declared together because they are one policy — how
    | long the platform waits before it believes a parcel arrived, before it pays
    | the seller, and before it stops accepting a return — and splitting the
    | declaration across three sprints would hide that.
    |
    */

    'windows' => [

        /*
        | How long a parcel may be in transit before the platform infers it
        | arrived (ADR-064). Deliberately generous: this number's failure mode is
        | asymmetric. Too long and a seller waits a few extra days to be paid; too
        | short and the platform tells a buyer their parcel was delivered while
        | they are still waiting for it, then starts their return clock running.
        */
        'transit_days' => (int) env('SHIPPING_TRANSIT_DAYS', 3),

        /*
        | How long after delivery a seller's payout becomes eligible. It is the
        | return window by design (Shipping.md §4): a seller must not be paid for
        | goods the buyer can still send back, or the platform is recovering money
        | it has already handed over.
        */
        'payout_hold_days' => (int) env('SHIPPING_PAYOUT_HOLD_DAYS', 14),

        /*
        | How long after delivery the buyer may still return. 14 days is the
        | Turkish distance-selling right of withdrawal (cayma hakkı); shortening
        | it below that is not a configuration choice the law allows, which is
        | worth knowing before anyone edits the setting.
        */
        'return_days' => (int) env('SHIPPING_RETURN_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Carrier integration (ADR-063)
    |--------------------------------------------------------------------------
    |
    | THERE IS NO CARRIER API IN v1 and this key says so out loud. Tracking is
    | manual: the seller types a number and the storefront builds a link from the
    | carrier's own template. When an integration lands it goes behind a
    | provider-agnostic tracking port and this becomes its discriminator — the
    | same shape `payment.gateway` has.
    |
    | `null` is the honest default. A key naming a driver that does not exist
    | would read as "somebody wired this up and it is off".
    |
    */

    'tracking_provider' => env('SHIPPING_TRACKING_PROVIDER'),

];
