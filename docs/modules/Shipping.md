# Shipping

**Status: SPEC — not built. Approved architecture (owner, 2026-08-05); ADR-063–064.**

Shipping is the module that tracks a paid order from the seller's hands to the buyer's
door, and turns "delivered" into the two things that wait on it: **when the seller gets
paid** (payout, delivered + N days) and **when the buyer can still return** (the refund
window). It is the newest module after Payment.

It is what Payment's two open follow-ups needed: ADR-060/062 left payout **manual only**
and P5 left the customer refund **admin-only**, both because there was no notion of
delivery yet. Shipping supplies it.

---

## 1. What Shipping is — and is not

**Shipping IS:** one shipment per paid order; the seller marking it shipped with a cargo
company and a tracking number; inferring delivery (a transit period, or the buyer
confirming early); and emitting **`ShipmentDelivered`** so Payment can release payout and
open the return window.

**Shipping is NOT** the checkout, the order, or the money. **v1 charges no shipping fee**
(ADR-063) — the storefront's "200 TL üzeri kargo bedava" is the whole policy — so Shipping
touches **no price, no KDV, no commission**. It is not a cargo-label printer or a cargo
API integration in v1 (manual tracking; the integration goes behind a port later). It is
not returns/refunds themselves — those live in Payment; Shipping only opens their window.

**Shipping imports NO module** — the platform's strict boundary (as Offer, Inventory,
Order and Payment before it). It reads Order through a **Core contract**, subscribes to
others' events **by class-string**, and any future cargo-carrier integration sits behind a
**provider-agnostic tracking port** (`ShipmentTrackingContract`) — none exists in v1
(manual). `LayeringTest` fails the build on any import.

---

## 2. The shipment aggregate & states (ADR-063)

**One shipment per order.** A checkout group split into N seller orders (ADR-052) becomes
N shipments — each seller ships their own order as one package. Multi-shipment / partial
shipment (a seller sending an order in two boxes) is **deliberately absent in v1**.

```
Order becomes `paid` (Payment)  →  Shipment created: `pending`  (hazırlanıyor)
        │  seller (panel): choose cargo company + tracking no → "kargoya verdim"
        ▼
   `shipped`   (in transit; shipped_at, cargo_company, tracking_number, tracking_url)
        │   ├─ buyer taps "Teslim aldım"                → delivered now
        │   └─ else: shipped_at + transit_days elapses  → delivered (auto)
        ▼
   `delivered` (delivered_at)  ──emits──▶  ShipmentDelivered
        │
        └─ (a return accepted later moves it to `returned` — driven by Payment's refund)
```

Public id is the uuid; internal id never leaves. State transitions are audited.

## 3. Delivery is inferred, not asserted by the seller (ADR-064)

The weak point of a manual model is "delivered," because the seller has an incentive to
claim it early (payout waits on it). So the seller **cannot** mark delivered. Delivery is
inferred two ways, whichever comes first:

- **The buyer confirms** — a "Teslim aldım" action on their order. The most trustworthy
  signal, and it lets an eager buyer start their own return clock early.
- **A transit period elapses** — `shipped_at + transit_days` (a configurable default,
  e.g. 3 days; a `settings()` value, not a constant). A scheduled job sweeps `shipped`
  shipments past their transit window and marks them `delivered`.

`delivered_at` is the resulting timestamp. When a real cargo-carrier integration lands
behind the tracking port, its actual delivery event **replaces the heuristic** — the
downstream (payout, return window) does not change, because it keys off `delivered_at`
however it was set.

## 4. The payoff — `ShipmentDelivered` drives Payment (ADR-064)

This is why Shipping exists. Payment **subscribes to `ShipmentDelivered` by class-string**
(it does not import Shipping), and `delivered_at` starts two clocks:

1. **Auto-payout.** `delivered_at + payout_hold_days` (e.g. 14 — the return window) → the
   seller's payout for that order becomes **eligible / auto-created**. This is the
   automatic payout ADR-060 deferred; the admin's **manual** payout stays. A seller is not
   paid before the buyer can no longer return the goods — which is exactly why the payout
   waits on delivery, not on payment.
2. **Return / refund window.** Within `delivered_at + return_days`, the buyer may request a
   return, which opens **Payment's customer refund** (the one P5 left admin-only, because
   "cancel before shipment vs return after delivery" could not be judged without a
   fulfilment state). Payment now has that state.

Both are **Payment enhancements driven by Shipping's event** — Payment is not frozen, and
they extend it without Shipping ever naming Payment or vice versa (class-string event
one way, no dependency the other).

## 5. Cargo companies — a lookup table (ADR-063)

`cargo_companies` (operator adds one without a release → a **table**, not an enum;
`is_active`, ADR-015): Yurtiçi, Aras, MNG, PTT, Sürat, HepsiJet, Trendyol Express, UPS…
Each carries a **tracking-URL template** (e.g. `https://.../{tracking_number}`) so the
storefront can turn a tracking number into a link without hard-coding carriers.

## 6. Surfaces

- **Seller** (Filament seller panel): a paid order shows "Kargoya ver" → pick cargo company
  + enter tracking number → `shipped`. The seller sees their shipments and their statuses.
  The seller **cannot** mark delivered (§3).
- **Customer** (storefront): "Siparişlerim" shows each order's shipment status + a tracking
  link + a **"Teslim aldım"** button while `shipped`. (Frontend — desktop session.)
- **Admin** (Filament admin panel): every shipment, oversight, and a corrective
  mark-delivered / re-open for the genuine exception (a mis-swept transit auto-delivery).

## 7. Boundaries & non-negotiables

- **No money.** v1 charges no shipping fee; Shipping writes no price/KDV/commission and the
  minor-units rule does not apply to it (it ships parcels, not kuruş).
- **Imports no module.** Core contracts + class-string events + the (empty in v1) tracking
  port only. `LayeringTest` enforces both directions.
- **The seller never sets `delivered`** — the one rule that keeps payout honest.
- State transitions audited; `current_actor()` / named guards; strict types.
- **`settings()` for the windows** (`transit_days`, `payout_hold_days`, `return_days`) — an
  operator tunes them without a release, and Settings returns a safe default if unreachable.

## 8. Internal phases (built in order)

- **S1** — Shipment aggregate + states + `cargo_companies` table + the seller "kargoya ver"
  flow (→ `shipped`, tracking). Core Order read for the paid orders to ship.
- **S2** — Delivery inference: the transit-period sweep job + the buyer "Teslim aldım"
  action → `delivered` + `ShipmentDelivered` event.
- **S3** — **Payment enhancement:** consume `ShipmentDelivered` → auto-payout at
  `delivered_at + payout_hold_days`; open the return window.
- **S4** — **Payment enhancement:** buyer-initiated return + **line-level partial refund**
  (refund a quantity of an order line: proportional commission + KDV reversal, PayTR
  partial refund, Inventory restock of that quantity).
- **S5** — **Storefront (frontend):** shipment status + tracking link + "Teslim aldım" on
  the order; the return-request UI.

## 9. Deliberately absent / follow-ups

- **Cargo-carrier API / label printing / auto-tracking** — the provider-agnostic port is
  there for it; v1 is manual. A future ADR + first adapter.
- **Shipping cost / paid shipping / per-seller shipping rules** — v1 is free; a priced
  shipping flow re-opens Order/Payment and is its own ADR.
- **Partial / multi-shipment** (one order in several boxes) — one shipment per order in v1.
- **Cross-border / customs, shipping insurance, scheduled delivery** — out of scope.

## 10. What this requires of other modules (contract-level)

- **Order** — a Core read of a seller's `paid` orders (to create/list their shipments) and
  the line data the partial refund (S4) needs; the order moves to a fulfilment/completed
  state on delivery. No Order import.
- **Payment** — consumes `ShipmentDelivered` (class-string) for auto-payout + the return
  window (S3), and gains buyer-initiated + line-level partial refund (S4). Payment names no
  Shipping class; Shipping names no Payment class.
- **Inventory** — the partial refund (S4) restocks a returned quantity through the same
  reservation/movement port Payment's refund already uses.

None is a new cross-module dependency: all go through Core and class-string events, exactly
as every module since Offer.
