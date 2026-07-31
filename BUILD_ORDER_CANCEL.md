# Work order — ADR-057: placement holds the reservation; actor-typed cancellation

**Disposable. `git rm` when done.** For the server-side session. Owner-approved 2026-07-31.
Closes Order **follow-up #1** (a cancelled *placed* order could not return stock). The ruling
is **already recorded owner-side** — **ADR-057** in the ADR record + the 001 amendment log,
an amendment banner in **Order.md** (top + this order), and **CLAUDE.md** — do **not**
re-author them; if any are missing after `git pull`, STOP and report (ADR-018). One commit
per lettered group, suite green (`php artisan test`), human pushes.

## What changes (and why)
The first Order build committed stock **at placement** (ADR-054 as first shipped). That left
no way to return stock when a *placed* order is cancelled (Inventory has no un-commit;
`release()` on a committed reference is a no-op). ADR-057 fixes it: **placement holds the
reservation instead of committing**, and **cancellation is typed by who cancels**.

## Hard rules (unchanged)
Order imports nothing (reads via Core contracts, **calls** `InventoryReservationContract`,
emits events consumed by class-string). `declare(strict_types=1)`; money integer minor units;
UUID public / id internal; no `dd/dump`; DTOs `...DTO`; side effects in `after()`. `LayeringTest`
green in both directions. Offer is **not frozen** (it gains one listener here).

## Build

### A — Placement holds, no commit (amends the P5 placement path)
- `PlaceOrderAction`: **remove the `commit()` call.** Placing a checkout group moves each
  order to `AwaitingPayment` and **keeps its reservation active** (held). Nothing commits this
  sprint — commit becomes Payment's job (documented; do not add a Payment gate here).
- **Expiry sweep** (`ExpireReservationsJob`): release + cancel only **un-placed `Pending`**
  checkouts older than the config window (30 min). **A placed `AwaitingPayment` order is NOT
  swept** — it holds until paid or cancelled.
- Tests: placing an order leaves `available` reduced (reserved), on_hand unchanged; the sweep
  cancels a stale Pending checkout but never a placed order.

### B — Actor-typed cancellation
Make `CancelOrderAction` (or split into intent-carrying actions) record **who** cancelled and
apply the stock rule. Every path is idempotent (Inventory primitives are idempotent on the
reference); a placed order can now be cancelled and return stock because it was never
committed.

| Cancelled by | Stock action |
|---|---|
| **Buyer** | `release(orderRef)` → returns to available |
| **Seller** | `release(orderRef)` **+ zero the seller's on-hand** (see C); warn the seller first (the confirm dialog states "stoğun sıfırlanacak") |
| **Admin** | `release(orderRef)` by default; an explicit "seller-fault / zero stock" option also zeroes (as the seller path) |
| **System / expiry** | `release(orderRef)` (Pending only, from A) |

- Record the canceller (actor type + reason) on the order for the audit trail.
- Tests: buyer-cancel returns stock; admin-cancel returns by default; expiry returns; double
  cancel does not double-release; the canceller is recorded.

### C — Seller-cancel zeroes on-hand at the source (Offer, NOT frozen)
The zero must stick and stay consistent with the seller's Offer form, so it happens at the
**Offer** (where the seller declares stock), flowing through the existing Offer→Inventory
mirror (ADR-048):
- Order emits **`OrderCancelledBySeller`** carrying `offer_uuid` / `variant_uuid` /
  `selling_org_uuid`.
- **Offer** subscribes **by class-string** (the pattern it already uses for Catalog events —
  no import of Order) and sets that offer's `stock_quantity` to **0**, emitting its existing
  `OfferStockChanged` → Inventory mirrors → `on_hand` becomes 0 → `available` 0.
- Order releases this order's reservation as in B. If other holds exist on the same variant,
  `available` floors at 0 (Inventory's non-negative guard); do not force other reservations.
- Tests: after a seller-cancel, the offer's stock is 0, Inventory on_hand is 0, the buy box
  drops the offer; a **second seller's** offer for the same variant is untouched (isolation);
  Order still imports no module and the Offer listener is class-string only (`LayeringTest`).

## Notes (don't re-litigate — report if you must deviate)
- **Commit is deferred to Payment** — placement holds. Do not add money or a payment gate.
- **Seller-zero zeroes the whole on-hand** for that variant (not just the ordered qty) — the
  owner's anti-oversell rule.
- **Returns/RMA** (post-payment restock, needing an Inventory restock primitive) stay **out
  of scope** — that is the Returns sprint.

## Finish
Update Order.md §3.3 (and §12 follow-up #1 → resolved by ADR-057) and Offer.md §15 (the new
`OrderCancelledBySeller` listener) to match what shipped. `git rm BUILD_ORDER_CANCEL.md`,
commit. Report the `php artisan test` count and a short note (place → cancel as buyer returns
stock; cancel as seller zeroes it). If anything conflicts with the docs chain, STOP and report.
