# Work order — Pre-shipment cancellation (backend) + ADR-065

**Status:** owner-approved 2026-08-06. Decision: **ADR-065**. Disposable — `git rm` when done.

**Session split:** BACKEND → the **server session**. The desktop session does the storefront
**buyer "İptal talebi"** UI (on a paid-unshipped order) AFTER C2's API exists — do **not**
touch `storefront/`. Build phase by phase, `make check` green + push + report after each.

**Sprint rule (ADR-018):** if anything here conflicts with the spec/ADR, STOP and report —
especially the module placement of the `CancellationRequest` aggregate (see Boundaries).

---

## The idea (ADR-065)

The **mirror of the return**: while a shipment is still `pending` (not shipped), a paid order
can be cancelled, and the refund is **`RefundLinesAction` unchanged** (proportional
commission + KDV reversal, PayTR partial refund, Inventory restock). Only the **triggers** and
the **shipment-`pending` gate** are new. Once `shipped`, cancellation is gone — ADR-064's
post-delivery return takes over.

- **The gate:** every cancel path first reads the order's shipment state through a Core
  contract (Shipping). If the shipment is not `pending` → refuse (404/409, never 500 — the
  uuid-shape rule still holds). No import of Shipping.
- **No seller penalty** in v1.

## C1 — Seller line-level cancel (immediate)

- **`CancelOrderLinesBySeller`** (seller): given the seller's own paid order + `lines[]` of
  `{id, quantity}`, gated on shipment `pending` → call `RefundLinesAction` for those lines
  (partial refund to buyer + restock). If every line's every unit ends up cancelled → order →
  `cancelled` and shipment → cancelled (Shipping consumes the event / Order sets its own state
  — same pattern as the return marking a shipment `returned`).
- Guard: seller may cancel only their own org's order; cannot cancel more than a line's
  remaining quantity (reuse the `RefundableLines` remaining-quantity check).
- **Seller Filament:** on a paid-unshipped order, a per-line "İptal et" with a quantity.

**Tests (pgsql):** seller cancels 1 of 2 → exact kuruş refund + restock of 1 + order stays
paid with the rest; cancel all → order cancelled + shipment cancelled; cannot cancel a
shipped order; cannot over-cancel; seller can't touch another org's order (404).

## C2 — Buyer cancel-request → seller approve/reject

- **`CancellationRequest`** aggregate: uuid, `order_uuid`, `requested_by`, `reason?`, `status`
  (`pending|approved|rejected`), timestamps. One open request per order (UNIQUE on
  `order_uuid` where `status = pending`).
- **`RequestOrderCancellation`** (buyer): gated on shipment `pending` + the order being the
  buyer's own → create a `pending` request. The buyer does **not** cancel — they ask.
- **`ApproveCancellation`** (seller): → **full-order** `RefundLinesAction` (every remaining
  line) + restock + order `cancelled` + shipment cancelled; request → `approved`.
- **`RejectCancellation`** (seller, optional reason): request → `rejected`; the order proceeds.
- **Seller Filament:** a cancellation-requests inbox (approve/reject).

**Storefront-facing API (for the desktop session):**
```
POST /api/v1/orders/{order}/cancellation-request  { reason? }  → 201, the request
GET  /api/v1/orders/{order}/cancellation-request               → status (or 404 none)
```
(The seller approve/reject is admin/seller-panel, not storefront.)

**Tests:** buyer requests on unshipped → pending; can't request on shipped (409); one open
request per order; seller approve → full refund + restock + cancelled; reject → order
proceeds, request rejected; buyer can't request another's order.

## Boundaries

- **Refund reuse:** `RefundLinesAction` (Payment) unchanged — same arithmetic/restock/ledger.
- **Placement:** the cancel-trigger actions live in Payment (where the refund is), reading
  Order lines + shipment state via Core — as `RequestReturnAction` does. The
  `CancellationRequest` aggregate + seller inbox sit with the order lifecycle; **you decide**
  Order vs Payment under `LayeringTest` and report it. No module imports another; cross-module
  is Core contracts + class-string events only.
- Money = integer kuruş; `current_actor()`/named guards; strict types; UUID public; audited.

## Frontend follow-up (desktop session — NOT you)

After C2: the storefront shows, on a paid-**unshipped** order, an **"İptal talebi"** button →
POST the request (reason) → "Satıcı onayında"; and if a request is rejected/approved, reflect
it. Nothing for you in `storefront/`.
