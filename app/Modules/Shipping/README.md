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
     │  buyer taps "Teslim aldım"                → delivered      ┐ S2
     └  shipped_at + transit_days elapses        → delivered      ┘
                                                       │
                                                 ShipmentDelivered
                                                       │
             Payment (S3): payout eligible at delivered_at + payout_hold_days
                           return window until delivered_at + return_days
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

## Deploy note

`shipping:backfill` gives every already-paid order the parcel it never got —
orders paid before this module existed have no shipment, and without one their
sellers can never be paid out. Idempotent; safe to re-run.
