# Shipping

**Status: S1–S4 BUILT (2026-08-05/06); only S5, the storefront, remains. ADR-063–064,
extended by ADR-065.** S1 shipped the shipment aggregate, the `cargo_companies` table and
the seller's "kargoya ver" flow; S2 shipped delivery inference and `ShipmentDelivered`; S3
and S4 are **Payment enhancements** that consume it — the payout hold, the return window,
and the buyer's return with a line-level partial refund (Payment.md §8). **ADR-065's
pre-shipment cancellation (C1) landed here too**, as the parcel's `cancelled` state and the
Core read port the whole cancellation gate turns on (§11).

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
  flow (→ `shipped`, tracking). Core Order read for the paid orders to ship. **Built
  2026-08-05 — see §11.**
- **S2** — Delivery inference: the transit-period sweep job + the buyer "Teslim aldım"
  action → `delivered` + `ShipmentDelivered` event. **Built 2026-08-05 — see §11.**
- **S3** — **Payment enhancement:** consume `ShipmentDelivered` → payout becomes
  ELIGIBLE at `delivered_at + payout_hold_days`; open the return window. **Built
  2026-08-05 — it lives in Payment, see Payment.md §8. Nothing here changed:**
  Shipping still emits one event and knows nothing about what it unlocks.
- **S4** — **Payment enhancement:** buyer-initiated return + **line-level partial refund**
  (refund a quantity of an order line: proportional commission + KDV reversal, PayTR
  partial refund, Inventory restock of that quantity). **Built 2026-08-06 — it lives in
  Payment, see Payment.md §8.** One thing landed here: the parcel becomes `returned`,
  moved by a class-string listener on `PaymentRefunded` and **only when the whole order
  has gone back** — a buyer who kept one of two shoes still has a delivered parcel.
- **S5** — **Storefront (frontend):** shipment status + tracking link + "Teslim aldım" on
  the order; the return-request UI.

## 11. What S1 shipped

| Area | Where |
|---|---|
| `Shipment` — one per paid order, UNIQUE on `order_uuid` (§2) | `Domain/Models/Shipment` |
| `ShipmentStatus`, `DeliveredVia` — the state machine and the provenance of a delivery date (§2–3) | `Domain/Enums/*` |
| `CargoCompany` — the operator-managed lookup + tracking-URL template (§5) | `Domain/Models/CargoCompany` |
| The eight TR carriers, seeded idempotently on `code` | `Database\Modules\Shipping\Seeders\CargoCompanySeeder` |
| A parcel per order on `PaymentSucceeded`, by CLASS-STRING (§1) | `Application/Listeners/CreateShipmentsOnPayment` |
| "Kargoya ver" — carrier + tracking number → `shipped` (§6) | `Application/Actions/MarkShippedAction` |
| The seller's shipment list, org-uuid scoped (§6) | `Presentation/Filament/Seller/Resources/ShipmentResource` |
| The admin's carrier list (§5) | `Presentation/Filament/Resources/CargoCompanyResource` |
| `shipping:backfill` — a parcel for orders paid before this module existed | `Presentation/Console/BackfillShipmentsCommand` |
| `orderFulfilment()` + `paidOrders()` on the Core Order port (§10) | `app/Core/Domain/Contracts/OrderQueryContract` |

### S2 — delivery inference

| Area | Where |
|---|---|
| `ShipmentDelivered` — **the event this module exists to emit** (§4) | `Domain/Events/ShipmentDelivered` |
| The one way a parcel becomes delivered, shared by both honest paths (§3) | `Application/Actions/Concerns/RecordsDelivery` |
| "Teslim aldım" — the buyer's confirmation (§3) | `Application/Actions/ConfirmReceiptAction` |
| The transit sweep, hourly, `settings('shipping.transit_days')` (§3) | `Application/Jobs/SweepTransitDeliveriesJob` + `routes/console.php` |
| `GET /orders/{order}/shipment`, `POST /orders/{order}/shipment/confirm` (§6) | `Presentation/Controllers/Api/ShipmentController` |
| `OrderStatus::Delivered`, moved by Order's own class-string listener | `Order\Application\Listeners\SettleOrdersOnPayment::onDelivered()` |
| The three windows as operator-tunable settings (§7) | `SettingGroup::Shipping` + `SettingsSeeder` |

**The two honest sources, and the absence of a third.** The buyer confirms, or the
clock runs out. `delivered_via` records which — an observed delivery and a guessed
one are worth different amounts in a dispute, and a single timestamp could not say.
The seller still has no route, no action and no permission that reaches delivery,
and the customer endpoint answers a seller with 404 like anybody else who does not
own the order.

**Re-stamping is what the idempotence protects.** A second "Teslim aldım" is a
no-op and the sweep skips anything already delivered — because `delivered_at` is a
payout schedule and a return deadline, and moving it silently extends both. The
sweep would also overwrite a BUYER-confirmed delivery with a guess, which is the
more expensive half.

**Deliberately absent in S1, and named so nobody looks for them:** nothing writes
`delivered_at` — no action, no route, no permission — because delivery is S2's
inference; there is no customer-facing API yet (S5's storefront reads it); and the
`ShipmentTrackingContract` port has no implementation and no interface file, because a
port with no adapter and no caller is a file that only documents an intention (§9).

**One correction worth recording.** The first version of `ShipmentPolicy` withheld the
Super Admin bypass from `deliver`, which cannot work — `Gate::before()` grants a Super
Admin every ability before any policy runs — and would have contradicted §6's corrective
admin lever anyway. The guarantee is STRUCTURAL: the operation does not exist.

### S4 — the goods come back

| Area | Where |
|---|---|
| `returned` — the one transition this module does not originate (§2) | `Application/Listeners/MarkShipmentsReturned` |

**Everything else about a return is Payment's**, and that division is the same one
Order keeps for `PaymentSucceeded`: another module announces a fact about money, and
the module that OWNS the state moves it. Payment does not touch a shipment; Shipping
does not know what a refund is worth.

**It moves only on a FULLY returned order.** `PaymentRefunded` carries the order uuid
exactly when every unit of every line has gone back — Payment sums that, because it
holds the line quantities and Shipping does not. A partial return arrives here as an
empty list and is silence.

**Idempotent by the transition table**, not by a flag: only a `delivered` shipment may
become `returned`, so a replayed event finds nothing to do.

### C1 — the parcel that never left (ADR-065)

| Area | Where |
|---|---|
| `ShipmentStatus::Cancelled` + `shipments.cancelled_at` (§2) | `Domain/Enums/ShipmentStatus` |
| `cancelled`, moved by Payment's refund exactly as `returned` is | `Application/Listeners/CancelShipmentsOnCancellation` |
| The cancellation GATE, answered for Payment (§10) | `Infrastructure/Queries/ShipmentQuery` + `Core\Domain\Contracts\ShipmentQueryContract` |

**The gate is this module's fact, and Payment could not have it any other way.**
ADR-065 lets a paid order be cancelled while its parcel is `pending` and not after —
so the whole feature turns on a state Shipping owns. It answers through a Core read
port, the first this module has published; Payment imports nothing.

**A missing shipment answers FALSE, not "probably fine".** An order with no row
cannot vouch for itself, and reading the absence as "not shipped yet" refunds a
parcel that may already be with a carrier. `shipping:backfill` is the fix.

**`Cancelled` and `Returned` are kept apart on purpose.** Both are terminal, both
are driven by a refund, and collapsing them into one "reversed" case would lose the
only distinction a future cancellation-rate penalty (ADR-065 defers it) could
count: a returned parcel was packed, handed over and carried; a cancelled one cost
the seller nothing but the buyer's time.

**Two listeners on one event, not one with a branch.** `MarkShipmentsReturned` and
`CancelShipmentsOnCancellation` both subscribe to `PaymentRefunded` and each reads
its `cause`. The transition table would already refuse a mix-up — a `pending` parcel
cannot become `returned` — but enforcing a rule by coincidence is not enforcing it.

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
  Shipping class; Shipping names no Payment class. Since ADR-065/C1 it also READS this
  module through `ShipmentQueryContract` — the cancellation gate — and announces a
  cancellation on `PaymentRefunded` with `cause = cancellation`, which this module's own
  listener turns into a `cancelled` parcel.
- **Inventory** — the partial refund (S4) restocks a returned quantity through the same
  reservation/movement port Payment's refund already uses.

None is a new cross-module dependency: all go through Core and class-string events, exactly
as every module since Offer.
