# Work order — build the Inventory module (availability authority + reservations)

**Disposable. `git rm` when done.** For the server-side session (has `vendor/`, DB, tests).
Owner-approved 2026-07-29. The authoritative design is
**[docs/modules/Inventory.md](docs/modules/Inventory.md)** — read it fully before writing
code. ADR-048…051 are **already recorded owner-side** (ADR record + 001 amendment log),
Inventory.md is APPROVED, and CLAUDE.md's prohibition is narrowed to Order/Payment — do
**not** re-author them; if any are missing after `git pull`, STOP and report (ADR-018).
Build **one commit per phase**, keep the suite green (`php artisan test`), human pushes.

## What Inventory is (one paragraph)
The **availability authority**: for each **(seller org, variant)** it holds **on-hand** and
**reserved**, and `available = on_hand − reserved` is what the buy box reads. The seller
keeps entering stock on the **Offer form**; Inventory mirrors on-hand by subscribing to
Offer stock events **by class-string**. It ships **reserve/release/commit** as a Core
command contract (Order calls it later), an **append-only movement ledger** as the source
of truth, and a **low-stock** signal. **No cart, order, payment, money, or multi-warehouse.**
See Inventory.md §0.

## Hard rules (build fails otherwise)
- **Imports nothing.** No `use App\Modules\Offer|Catalog|Organization|Store\...`. Consume
  Offer events **by class-string** (the pattern Offer uses for Catalog); read org tenancy
  via `OrganizationAuthorizationContract`; read Catalog by uuid via `CatalogQueryContract`
  if needed. `LayeringTest` stays green.
- **Movements are append-only** (non-negotiable #9) — the model refuses update/delete, like
  Audit/Activity. `on_hand`/`reserved` are projections written in the same transaction.
- **Non-negative + row-locked.** Reservation math runs under a row lock on the `StockItem`;
  `on_hand`/`reserved` never go negative; `reserve` fails when `available < qty`; all three
  primitives are idempotent on `referenceUuid`.
- **No money.** Stock is counts; the minor-units rule does not apply here. But UUID public /
  internal id never leaves; `declare(strict_types=1)`; Domain imports no Eloquent/Request/DB
  facade, no `cache()/request()/encrypt()`; DTOs `...DTO` in `Domain/DTOs/`; no `dd/dump`;
  side effects in `after()`; roles by name; policies check permissions.

## Build phases (one commit each)

**P1 — scaffold.** `app/Modules/Inventory/{Domain,Application,Infrastructure,Presentation}`,
service provider, `config/inventory.php` if needed, README, modules-index entry. Green.

**P2 — domain.** `StockItem` (§2.1), `StockMovement` (append-only, §2.2), `StockReservation`
(§2.3); enums `StockMovementType`, `ReservationStatus` (no `Enum` suffix); DTOs; events
(§6: `StockItemCreated / StockAdjusted / StockReserved / StockReleased / StockCommitted /
StockLowStockReached`); the **Core** `InventoryQueryContract` + `InventoryReservationContract`
interfaces (§5). Green.

**P3 — infra.** Migrations (stock_items, stock_movements append-only, stock_reservations;
indexes for the (org,variant) lookup and available computation); repositories (declare
eager loads on `$with`, strict mode); factories; the `InventoryQuery` +
`InventoryReservation` implementations. Movements + projection written in one transaction
under a row lock. Green.

**P4 — application (mirror + reservations + low-stock).** Actions (§9): `AdjustStockAction`
(from the Offer mirror), `ReserveStockAction` / `ReleaseStockAction` / `CommitStockAction`
(behind the reservation contract), `SetLowStockThresholdAction`. Low-stock rule (§3.3):
emit `StockLowStockReached` when a movement leaves `available ≤ threshold`, re-arm on the
way up. Each action: one row-locked transaction, movement + projection, event in `after()`.
Green.

**P5 — Offer wiring (Offer is NOT frozen).** Two small Offer-side changes + the Inventory
listeners:
1. **Inventory subscribes** to `OfferCreated / OfferStockChanged / OfferWithdrawn` by
   class-string and mirrors on-hand (§3.1). Confirm those events **carry variant_uuid,
   product_uuid, selling_org_uuid and the quantity**; if a field is missing, **add it to the
   Offer event** (Offer not frozen) so Inventory consumes it blind — never import Offer.
2. **Buy box reads availability from Inventory:** swap Offer's in-stock test from
   `offer.stock_quantity > 0` to `InventoryQueryContract::isAvailable(variant, org)`.
   Functionally identical this sprint (reserved = 0) — a regression guard, not a behaviour
   change. Keep `Offer.stock_quantity` as the seller's declared on-hand (the entry point).
Green, and the Offer suite stays green.

**P6 — presentation.** Seller **stock view** (read-only: the seller's stock items with
on-hand / reserved / available / low-stock flag + per-item movement history; entry stays on
the Offer form), tenancy-scoped via `organizationIdsForUser()`. Admin **oversight** (view
across sellers, no editing seller stock), gated on `inventory.*` perms registered through
`PermissionRegistry` + attached in `RolePermissionSeeder`. `InventoryPolicy` (seller read via
org capability; admin via perms). Green.

**P7 — hardening.** Tests + docs + sweep:
- Inventory imports no module (`LayeringTest`); movements are append-only (refuse
  update/delete).
- Mirror: `OfferStockChanged` moves on-hand; `OfferWithdrawn` zeroes it.
- Reservations: `reserve` fails at `available < qty`; two concurrent reserves cannot both
  take the last unit (row lock); `commit` lowers on_hand+reserved; `release` lowers reserved;
  all idempotent on the reference.
- Low-stock fires crossing down, re-arms crossing up.
- Buy box still excludes out-of-stock via the Inventory read (parity with before).
- Add an Inventory.md §12-style "delivered / deliberately absent / follow-ups" section.

## Notes / choices already made (don't re-litigate — report if you must deviate)
- **Single pool per (org, variant)** — no location dimension (ADR-051).
- **On-hand entry stays on the Offer form**; Inventory mirrors (ADR-048).
- **Low-stock in v1**; per-item threshold + event.
- **Reservations shipped but no live caller** — Order calls them later (ADR-049).
- Migrations need running on the server after merge (`php artisan migrate --force`).

## Finish
`git rm BUILD_INVENTORY.md`, commit. Report the `php artisan test` count, the ADR entries
confirmed present, and a short live/Livewire note (seller sees stock + movements; a reserve
holds a unit; low-stock flags). If anything conflicts with the docs chain, STOP and report.
