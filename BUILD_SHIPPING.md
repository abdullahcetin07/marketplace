# Work order — Shipping module (backend), phased S1–S5

**Status:** owner-approved 2026-08-05. Spec: [docs/modules/Shipping.md](docs/modules/Shipping.md).
Decisions: **ADR-063** (seller-fulfilled manual tracking, free shipping, one shipment/order),
**ADR-064** (delivery inferred not seller-asserted; ShipmentDelivered drives auto-payout +
return window + partial refund). Disposable — `git rm` when the module lands.

**Session split:** BACKEND → the **server session**. The desktop session does the storefront
(S5: shipment status + tracking link + "Teslim aldım" + return-request UI) AFTER S1/S2's API
exists — do **not** touch `storefront/`. Build **phase by phase**, `make check` green + push +
report after each phase before the next.

**Sprint rule (ADR-018):** if anything here conflicts with the spec or an ADR, STOP and
report. The spec outranks this file.

---

## Non-negotiables for THIS module

- **No money.** Shipping writes no price/KDV/commission; the minor-units rule does not apply.
- **Imports NO module.** Core contracts + class-string events + the (empty) tracking port
  only. `LayeringTest` green both directions.
- **The seller CANNOT set `delivered`** (ADR-064) — enforce it in the action/policy, not just
  the UI. Delivery is buyer-confirm or transit-sweep only.
- **Windows are `settings()`** (`transit_days`, `payout_hold_days`, `return_days`) — operator-
  tunable, safe defaults if Settings is unreachable.
- State transitions audited; `current_actor()`/named guards; strict types; UUID public.

## Scaffold (before S1)

`app/Modules/Shipping/{Domain,Application,Infrastructure,Presentation}` + provider + config +
README + `LayeringTest` registration. `PermissionRegistry` for shipping abilities
(seller: ship own; admin: oversight). Register in `docs/modules/README.md`.

---

## S1 — Shipment aggregate + cargo companies + "kargoya ver"

1. **`cargo_companies`** lookup table (`is_active`, ADR-015): name, code, `tracking_url_template`
   (e.g. `https://.../{tracking_number}`). Seed the common TR carriers (Yurtiçi, Aras, MNG,
   PTT, Sürat, HepsiJet, Trendyol Express, UPS). Admin Filament resource to manage.
2. **`Shipment` aggregate** — one per order: uuid, `order_uuid`, `seller_org_uuid`, `status`
   (`pending|shipped|delivered|returned`), `cargo_company_id?`, `tracking_number?`,
   `shipped_at?`, `delivered_at?`, `delivered_via?` (`buyer|transit_sweep|carrier`), timestamps.
   A shipment is created (`pending`) when an order becomes `paid` — subscribe to Payment's
   paid event **by class-string** (Shipping imports nothing), idempotently (one shipment per
   order, UNIQUE `order_uuid`).
3. **`MarkShippedAction`** (seller) — pick cargo company + tracking number → `shipped`,
   `shipped_at = now()`. Emits `ShipmentShipped`. Guarded so a seller ships only their own
   org's order (read the order's seller via the Core Order contract; no Order import).
4. **Seller Filament resource** — a paid order shows "Kargoya ver"; lists the seller's shipments
   + statuses. Read the seller's paid orders through the **Core Order read contract**.
5. **Order read contract** (Core) — extend it (or add one) so Shipping can list a seller's
   `paid` orders + the order's seller/lines. Coordinate the addition exactly as Payment added
   its Order reads (Order-owned, no Shipping import).

**Tests:** shipment auto-created on paid (idempotent, one per order); seller can ship only own
order; ship sets tracking + `shipped`; non-uuid/unknown 404 not 500 (the trap — 8th watch).

## S2 — Delivery inference + `ShipmentDelivered`

1. **`ConfirmReceiptAction`** (buyer) — "Teslim aldım" on a `shipped` shipment of the buyer's
   own order → `delivered`, `delivered_at = now()`, `delivered_via = buyer`. Buyer-scoped.
2. **Transit sweep** — a scheduled job (Horizon/scheduler) finds `shipped` shipments where
   `shipped_at + settings(transit_days) < now()` and not delivered → `delivered`,
   `delivered_via = transit_sweep`. Idempotent.
3. Both paths emit **`ShipmentDelivered`** (with `order_uuid`, `seller_org_uuid`,
   `delivered_at`). Order moves to a `delivered`/completed fulfilment state via Order's own
   class-string listener (Shipping does not set Order's state).
4. **Seller/admin CANNOT deliver** — assert it (only buyer-confirm or sweep set `delivered`).

**Tests:** buyer confirm → delivered + event; sweep after transit → delivered + event; sweep
does not touch already-delivered; seller cannot deliver; buyer can't confirm another's order.

## S3 — Payment enhancement: auto-payout + return window (Payment module, class-string)

Payment subscribes to `ShipmentDelivered` **by class-string** (no Shipping import):
- schedule/mark the seller's payout **eligible** at `delivered_at + settings(payout_hold_days)`
  — auto-create the payout (or flag it available for the admin batch). Manual payout stays.
- open the **return window** until `delivered_at + settings(return_days)` (used by S4).
Record `delivered_at` on the payment/order side so the windows are queryable.

**Tests:** delivered → payout becomes eligible only after the hold; return window open/closed
by the clock; no payout before delivery.

## S4 — Payment enhancement: buyer return + line-level partial refund

- **`RequestReturnAction`** (buyer, within the return window) for specific order **lines +
  quantities**.
- **Line-level partial refund** in Payment: refund a quantity of an order line →
  `unit_price × qty + proportional KDV`; reverse commission **proportionally** (the frozen
  rate × the refunded base); **PayTR partial refund** (amount-based); **Inventory restock** of
  that quantity (the restock verb ADR-049/P5 added); ledger `refund_debit` +
  `refund_commission_credit` for the partial amount; shipment → `returned` when fully returned.
- Guard: cannot refund more than the line's remaining (un-refunded) quantity; idempotent.

**Tests (pgsql):** partial refund of 1 of 2 units — exact kuruş, proportional commission,
restock of 1, ledger reverses the partial; cannot over-refund; full return marks shipment
returned; refund-after-payout still safe (ledger sum).

## S5 — Storefront (desktop session — NOT you)

After S1/S2: the order shows its shipment status + tracking link (built from the carrier's
`tracking_url_template`) + a **"Teslim aldım"** button while `shipped`, and a return-request UI
within the window. Nothing for you in `storefront/`.

## After each phase

`make check` green, commit + push, report: the new events (class names for the desktop/other
consumers), the API signatures S5 needs (list shipments for an order, confirm receipt, request
return), and the settings keys + defaults.
