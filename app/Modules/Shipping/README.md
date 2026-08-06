# Shipping

Turns a paid order into a **delivery date** — the one fact the rest of the
platform is waiting for. One parcel per paid order, the seller hands it to a
carrier, and delivery is **inferred, never asserted by the seller**.

Full specification: [docs/modules/Shipping.md](../../../docs/modules/Shipping.md).
Decisions: ADR-063 (seller-fulfilled manual tracking, free shipping, one shipment
per order), ADR-064 (delivery inferred; `ShipmentDelivered` drives payout and the
return window).

## The shape

```
Payment: PaymentSucceeded  ──(class-string)──▶  Shipment created: pending

seller (panel) → pick carrier + tracking number → shipped   (shipped_at)
     │  buyer taps "Teslim aldım"                → delivered  (via=buyer)
     └  shipped_at + transit_days elapses        → delivered  (via=transit_sweep)
                                                       │
                                                 ShipmentDelivered
                                                       │
                    Order (now): status → delivered, by class-string
             Payment (S3): payout eligible at delivered_at + payout_hold_days
                           return window until delivered_at + return_days
                                                       │
                    buyer returns lines within the window (Payment S4)
                                                       │
                                                 PaymentRefunded
                                                       │
      Shipment → returned, by class-string — ONLY when the whole order came back
```

## What bites

- **The seller cannot mark it delivered.** Payout waits on delivery, so a seller
  asserting it would be asserting their own payday (ADR-064). The guarantee is
  STRUCTURAL rather than policy-shaped: S1 has no action, no route, no form and no
  permission that writes `delivered_at` — which is stronger than a denial, because
  `Gate::before()` grants a Super Admin every ability before any policy runs.
  `ShipmentPolicy::deliver()` states the refusal anyway, so a future "teslim
  edildi" button meets a documented denial rather than a missing method.
- **"Kargoya ver" is a one-way door.** A second call is a REFUSAL, not a no-op —
  the opposite of how this codebase usually treats a retry. Silently accepting it
  would either discard a corrected tracking number or silently keep the old one,
  and both leave the buyer with a link to somebody else's parcel.
- **One shipment per order, enforced by a UNIQUE index.** The row is created from
  a payment event that PayTR delivers many times; the index, not the
  check-then-insert, is what actually holds under a race.
- **No money exists in this module.** No price, no KDV, no commission column —
  v1 ships free (ADR-063), so the minor-units rule does not apply here at all.
  It counts parcels, not kuruş.
- **`delivered_via` is provenance, not decoration.** An inferred delivery date and
  a buyer-confirmed one are worth different amounts in a dispute, and a single
  timestamp could not say which it was.
- **It imports no module.** Orders arrive through `OrderQueryContract`; the
  payment event arrives as a class-string. `LayeringTest` fails the build on any
  import, both directions.

- **`delivered_at` is never re-stamped.** A second "Teslim aldım" is a no-op and
  the sweep skips anything already delivered — that date is a payout schedule and
  a return deadline, and moving it silently extends both. The sweep would also
  overwrite a buyer-confirmed delivery with a guess, which is the more expensive
  half.
- **The windows are `settings()`, not constants.** `shipping.transit_days` (3),
  `payout_hold_days` (14), `return_days` (14) — operations tunes them without a
  release, with `config('shipping.windows.*')` as the fallback because a sweep that
  stopped running over a missing settings row would stop paying sellers.

## Deploy note

The transit sweep is scheduled hourly in `routes/console.php`, so the scheduler
must actually be running — without it no delivery is ever inferred and no seller is
ever paid.

`shipping:backfill` gives every already-paid order the parcel it never got —
orders paid before this module existed have no shipment, and without one their
sellers can never be paid out. Idempotent; safe to re-run.
