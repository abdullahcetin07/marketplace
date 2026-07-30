# Inventory Module Specification

**Status: APPROVED 2026-07-29 — building.** The owner approved the design; the §0
decisions and the §10 rulings are ratified. **ADR-048 … ADR-051 are recorded** in
[docs/Architecture_Decision_Record.md](../Architecture_Decision_Record.md), with their
mirror in the amendment log at the end of [docs/001_Architecture.md](../001_Architecture.md)
(the way Store landed ADR-032…036, Catalog ADR-037…041, Offer ADR-042…046), and CLAUDE.md
narrows the module prohibition to Order/Payment. This document states each decision **and
its cost**, per project culture. Build order: [BUILD_INVENTORY.md](../../BUILD_INVENTORY.md).

Inventory is the next major sprint after **Offer** (complete, not frozen). It makes stock
**real**: it becomes the platform's authority for how much of a variant a seller can
actually sell right now, and it ships the **reservation** mechanism that stops two buyers
from taking the same last unit — the machinery **Order** will call when it exists. **Cart,
checkout, orders, payment, multi-warehouse and supplier restocking are out of scope** and
land in later, separately-reviewed sprints.

---

# 0. Scope and the decisions

## 0.1 What Inventory IS

Inventory is the **availability authority**. For each **(seller organization, variant)**
it holds **on-hand** (units the seller declares they have) and **reserved** (units held by
in-flight checkouts), and answers the one question no other module can: **`available =
on_hand − reserved`** — how many can be sold this instant.

It owns three things: the **stock record** (on-hand / reserved / low-stock threshold per
seller-variant), the **append-only movement ledger** that is the source of truth for how
those numbers got there, and the **reservation primitives** (reserve / release / commit)
that Order will drive.

## 0.2 What Inventory is NOT (and where those live)

| Concern | Owner |
|---|---|
| Price, the priced listing, the buy box | **Offer** (shipped) |
| Product / variant definition | **Catalog** (shipped) |
| Cart, checkout, order lines, decrementing stock on a real sale | **Order** (later) |
| Money, commission, payout | **Payment / Finance** (later) |
| Multiple warehouses / locations, stock transfers between them | **later, additively** (§0.7) |
| Supplier purchase orders, restocking workflow | **out of scope** |

**Inventory does not sell anything and does not know about money.** It counts units and
lends them out under reservation. A real decrement happens only when Order **commits** a
reservation — and Order does not exist yet, so nothing decrements on-hand this sprint
except the seller's own edits.

## 0.3 ADR-048 — Inventory is the availability authority; on-hand is mirrored from the Offer

Inventory becomes the source of truth for **availability** (ADR-043). But the **seller
still enters stock on the Offer form** (owner decision, 2026-07-29) — that field stays.
Inventory keeps its on-hand in sync by **subscribing to the Offer's stock events by
class-string** (`OfferCreated`, `OfferStockChanged`, `OfferWithdrawn`) — the same
name-not-an-import coupling Offer uses for Catalog's events — and recording each as a
seller-adjustment movement. It then layers **reserved** on top, and `available = on_hand −
reserved` is what the buy box reads (via the Core `InventoryQueryContract`), not
`Offer.stock_quantity`.

**Refines ADR-043.** The seller's declared **on-hand** lives on the Offer as the input;
the sellable **available** figure is derived by Inventory. Until Order exists `reserved`
is always 0, so `available == on_hand == Offer.stock_quantity` and nothing visibly
changes — the point is that reservations now have an authority to flow into.

**Cost.** The same number (on-hand) exists in two places — the Offer column the seller
types into and Inventory's mirror — kept consistent by a synchronous event, not a shared
row. A dropped/outof-order event would desync them; we accept it because the alternative
(Offer importing Inventory to write through, or Inventory owning the seller's entry UI
against the owner's decision) is the worse coupling. The mirror is rebuildable from the
Offer at any time.

## 0.4 ADR-049 — Reservation primitives ship as a Core command contract, before Order

Inventory exposes **reserve / release / commit** as a Core **`InventoryReservationContract`**
(a command port, the write-side sibling of the read-only query contracts). Order will call
it; this sprint it is exercised by tests, not by a real checkout.

- **reserve**(org, variant, qty, referenceUuid) — if `available ≥ qty`, raise `reserved`
  by qty and record the reservation under `referenceUuid`; else fail. Idempotent on the
  reference.
- **release**(referenceUuid) — a cancelled/expired checkout returns its units: lower
  `reserved`.
- **commit**(referenceUuid) — a completed sale: lower **both** `on_hand` and `reserved` by
  the reserved qty (the units truly leave).

**Cost.** We build and test machinery with no live caller for one sprint, and we commit to
a reservation API shape before Order can prove it. We accept it because Inventory's whole
reason to precede Order is to give Order a stock authority to call; shipping the counter
without the primitives (the rejected Option 2) would make this sprint little more than
copying a number into a new table.

## 0.5 ADR-050 — The append-only movement ledger is the source of truth

Every change — seller adjustment, reservation, release, commit — is an **append-only
`StockMovement`** row (signed `on_hand_delta` / `reserved_delta`, a type, and a reference).
The stock record's `on_hand` and `reserved` are **projections** of the ledger, updated in
the same transaction and rebuildable from it. Movements are never updated or deleted (the
Audit/Activity append-only rule, non-negotiable #9, applied to stock).

**Cost.** Two writes per change (the movement + the projection) and a ledger that grows
unbounded, against a single mutable counter. We accept it because stock disputes ("the
system says 0 but I never sold that many") are answerable only with a history, and because
reservations make a bare counter ambiguous (a drop could be a sale or a hold) — the ledger
records which.

## 0.6 ADR-051 — Single stock pool per (org, variant) in v1

Stock is **one pool per (seller organization, variant)** — no warehouse/location
dimension. Multi-warehouse returns later, additively, by adding a location to the stock
record and the movement, without reshaping the reservation contract.

**Cost.** A seller with real multiple warehouses cannot model them yet, and "which
location ships this" has no answer this sprint. We accept it because there is no Order or
Shipping module for a location to feed, so multi-warehouse now would be untestable
structure with no consumer — the same reasoning that kept Offer single-currency.

## 0.7 Scope of THIS sprint

**In scope:** the stock record (on-hand / reserved / low-stock threshold per
seller-variant); the append-only movement ledger; on-hand mirroring from Offer events;
the reservation primitives (Core command contract); the Core `InventoryQueryContract`
(available / on-hand / is-available); the buy box reading availability from Inventory; a
**low-stock signal** (event + seller-visible); a seller **stock view** (read-only
movement history + current on-hand/reserved/available + low-stock; entry stays on the
Offer form); admin oversight; events.

**Out of scope:** cart/checkout/order (no real decrement — only `commit` via the contract,
which Order will call); multi-warehouse; stock transfers; supplier purchase orders; money.

**Cost of phasing.** Reservations work but nothing reserves; the ledger records seller
edits and test-driven holds only. We accept it — building the authority before its caller
is the same discipline that phased Catalog before Offer.

---

# 1. Purpose

## 1.1 Responsibilities
- Own the stock record per (seller org, variant): on-hand, reserved, low-stock threshold.
- Keep on-hand mirrored from the seller's Offer entry (by class-string event).
- Own the append-only movement ledger (source of truth for on-hand/reserved).
- Provide reservation primitives (reserve/release/commit) as a Core command contract.
- Answer availability (`InventoryQueryContract`) for the buy box and downstream.
- Emit a low-stock signal; expose a seller stock view + admin oversight.

## 1.2 Non-responsibilities
Price/buy box (Offer); product (Catalog); cart/checkout/order (Order); money (Payment);
multi-warehouse & transfers (later). See §0.2.

## 1.3 Module boundaries
Standard modular monolith (ADR-002): `Domain / Application / Infrastructure /
Presentation`. Cross-module communication is events + Core contracts only; `LayeringTest`
enforces no cross-module imports. **Inventory imports no module** — it consumes Offer's
events by class-string and reads Catalog/Offer by uuid through Core contracts.

## 1.4 Relationships
- **Offer** — Inventory subscribes to `OfferCreated / OfferStockChanged / OfferWithdrawn`
  (by class-string) to mirror on-hand; Offer's buy box reads
  `InventoryQueryContract::isAvailable`. Referenced by variant/org/offer uuid.
- **Catalog** — reads variant/product existence via `CatalogQueryContract` if needed.
- **Organization** — tenancy via `OrganizationAuthorizationContract::organizationIdsForUser()`
  for the seller stock view; stored as `selling_org_id` (internal) + `selling_org_uuid`.
- **Order (later)** — the sole real caller of `InventoryReservationContract`.
- **Localization / Audit** — movements are auditable; low-stock is a notification seam.

---

# 2. Domain Model

## 2.1 `StockItem` (aggregate root) — one per (selling_org, variant)
`id` · `uuid` · `variant_uuid` + `product_uuid` (denormalized) · `offer_uuid` (the owning
offer; one active offer per (org,variant)) · `selling_org_id` (internal, tenancy) +
`selling_org_uuid` · `on_hand` (int ≥ 0, projection) · `reserved` (int ≥ 0, projection) ·
`low_stock_threshold` (int, nullable — seller sets) · timestamps. **`available` is
computed** (`on_hand − reserved`), never stored.

## 2.2 `StockMovement` (append-only ledger)
`id` · `uuid` · `stock_item_id` · `type` (`StockMovementType`) · `on_hand_delta` (signed)
· `reserved_delta` (signed) · `reference` (nullable — a reservation/order uuid) ·
`note` · `created_at`. **No updates, no deletes** (non-negotiable #9). `on_hand` /
`reserved` are `SUM` of the deltas.

## 2.3 `StockReservation`
`id` · `uuid` · `reference_uuid` (unique — the caller's key) · `stock_item_id` ·
`quantity` · `status` (`ReservationStatus`) · timestamps. Lets `release`/`commit` find a
reservation by its reference.

## 2.4 Enums (module-owned, no `Enum` suffix — ADR-007)
- `StockMovementType` — `SellerAdjustment`, `Reserved`, `Released`, `Committed`.
- `ReservationStatus` — `Active`, `Released`, `Committed`.

---

# 3. Business Rules

## 3.1 On-hand mirroring (from Offer)
`OfferCreated` → ensure a `StockItem` for (org, variant) with `on_hand` = the offer's
declared stock (a `SellerAdjustment` movement). `OfferStockChanged` → a `SellerAdjustment`
movement bringing `on_hand` to the new value. `OfferWithdrawn` → `on_hand` set to 0
(the offer is gone; reservations, if any, are the caller's to release). Events carry
variant/product/org uuid + quantity so Inventory consumes them **blind** (no Offer import);
if any field is missing on the shipped Offer event, Offer (not frozen) adds it.

## 3.2 Reservation invariants (§0.4)
`reserve` succeeds only when `available ≥ qty`; it never drives `available` negative.
`release`/`commit` act only on an `Active` reservation and are idempotent on the reference
(a repeated commit is a no-op, not a double decrement). `commit` lowers on_hand and
reserved together; `release` lowers reserved only.

## 3.3 Low-stock signal (v1)
When a movement leaves `available ≤ low_stock_threshold` (and a threshold is set), emit
`StockLowStockReached`; the seller sees it (panel badge + notification seam). Re-crossing
upward re-arms it (no repeated spam while it stays low).

## 3.4 Non-negative & concurrency
`on_hand` and `reserved` never go negative; reservation math runs under a row lock on the
`StockItem` so two concurrent reserves cannot both consume the last unit (the exact race
Inventory exists to prevent, now testable even before Order).

---

# 4. Seller & admin surfaces
- **Seller stock view** (read-only entry stays on the Offer form): a list of the seller's
  `StockItem`s (product/variant, on-hand, reserved, available, low-stock flag) + a movement
  history per item. Tenancy-scoped via `organizationIdsForUser()`.
- **Admin oversight**: view stock across sellers; no editing of seller stock (that is the
  seller's, through the Offer). Gated on `inventory.*` permissions.

---

# 5. Contracts (Core)

## 5.1 `InventoryQueryContract` (read)
- `availableFor(string $variantUuid, string $sellingOrgUuid): int`
- `isAvailable(string $variantUuid, string $sellingOrgUuid, int $qty = 1): bool`
- `onHandFor(...) : int`
Used by Offer's buy box (in-stock test) and any downstream. Returns plain scalars/DTOs.

## 5.2 `InventoryReservationContract` (command — for Order)
- `reserve(string $sellingOrgUuid, string $variantUuid, int $qty, string $referenceUuid): bool`
- `release(string $referenceUuid): void`
- `commit(string $referenceUuid): void`
The only sanctioned way another module mutates stock. Order is its first (later) caller.

---

# 6. Events (module-owned, past tense)
`StockItemCreated`, `StockAdjusted`, `StockReserved`, `StockReleased`, `StockCommitted`,
`StockLowStockReached`. Audit records movements; Notification carries low-stock; Search/
Storefront may react to availability changes (the buy box already reads live).

---

# 7. Policies — roles & capabilities
- **Seller** — reads own stock via the org capability (the `organizationIdsForUser` +
  capability check pattern); the seller does not write stock here (writes via the Offer).
- **Admin** — `inventory.*` (`view_any`, `view`) via `PermissionRegistry`, attached in
  `RolePermissionSeeder`. Oversight only; no editing seller stock.

---

# 8. Non-negotiables recap (they apply here too)
`declare(strict_types=1)`; UUID public / internal id never leaves; Domain imports no
Eloquent/Request/DB facade, no `cache()/request()/encrypt()` (ADR-019); movements are
append-only (#9); no `dd/dump/die`; roles by name; policies check permissions; DTOs
`...DTO` in `Domain/DTOs/` (ADR-021); side effects in `after()`. **No money here** — stock
is counts, not currency, so the minor-units rule simply does not apply.

---

# 9. Proposed Application actions (this sprint)
`AdjustStockAction` (from the Offer mirror), `ReserveStockAction`, `ReleaseStockAction`,
`CommitStockAction` (behind the reservation contract), `SetLowStockThresholdAction`. Each:
one transaction (row-locked on the `StockItem`), a movement + projection write, event in
`after()`.

---

# 10. Open rulings to confirm at approval
1. **On-hand entry stays on the Offer form** (owner-confirmed); Inventory mirrors by event.
2. **Low-stock in v1** (owner-confirmed) — a per-item threshold + `StockLowStockReached`.
3. **Single pool per (org, variant)** (owner-confirmed); multi-warehouse deferred.
4. **Offer events may need extra payload** (variant/product/org uuid + qty) so Inventory
   consumes them blind — an Offer change (not frozen), confirm at build.
5. **Buy box switches to `InventoryQueryContract::isAvailable`** — an Offer change (not
   frozen); functionally identical this sprint (reserved = 0).

---

# 11. Phasing after this sprint
Inventory (this) → **Order** (cart, checkout, order lines; the first real caller of
`InventoryReservationContract`; tax) → **Payment/Finance** (commission, payout,
settlement). Each is its own spec + architecture review.

## Ratification checklist
- [x] Record ADR-048…051 in the ADR record + amendment log (2026-07-29).
- [x] Confirm the §10 rulings (owner-approved: on-hand on the Offer form, low-stock in v1,
      single pool).
- [x] Narrow the CLAUDE.md module prohibition to Order/Payment.
- [ ] Build in phases (scaffold → domain → infra → application/contracts → Offer wiring
      (mirror + buy-box read) → presentation → tests), one commit per phase, suite green,
      human pushes.
