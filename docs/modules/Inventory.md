# Inventory Module Specification

**Status: COMPLETE (v1.0), NOT frozen — 2026-07-30.** Built in phases P1–P7; what
shipped, what is deliberately absent, the deviations and the follow-ups are in **§12**.
Deliberately **not frozen**: **Order** is the next sprint and is the first real caller of
the reservation contract (§5.2, ADR-049), so it will need to reach in here. The §0
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
- **restock**(reference, quantity?) — *added 2026-08-04 by Payment P5 and made
  QUANTITY-AWARE on 2026-08-06 by Shipping S4, amending this ADR twice.* A sale was
  UNDONE: raise `on_hand` and leave `reserved` alone. `quantity` null means all of it, so
  P5's callers were unchanged; a line-level refund passes the units that actually came
  back, and asking for more than is still out there returns what is left, never more. The hold ended when the sale
  completed and does not come back, so the units are simply sellable again; restoring
  `reserved` too would hold stock for an order that has been refunded. A no-op on any
  reference that is not `committed` — the guarantee that stops a retried refund inventing
  stock that does not physically exist. **Deliberately not `release` called late:**
  reversing a sale and abandoning a hold are different business events, and conflating
  them in the append-only ledger makes "why did my stock go up?" unanswerable, so it
  carries its own movement type, terminal reservation state and timestamp
  (Order.md §12.5, now closed).

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
- `StockMovementType` — `SellerAdjustment`, `Reserved`, `Released`, `Committed`,
  `Restocked` (P5).
- `ReservationStatus` — `Active`, `Released`, `Committed`, `Restocked` (P5). Three
  terminal states, not two: a hold that never became a sale and a sale that was undone
  are different facts, and a reservation's history is where a dispute is settled.

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
reserved together; `release` lowers reserved only. `restock` (P5) acts only on a
`Committed` reservation, raises on_hand only, and is likewise idempotent — a repeated
restock is a no-op, which matters more here than anywhere else in this module: a double
restock would invent stock that does not exist, and the seller would sell it to somebody.
**S4 changed how that idempotence is expressed, not whether it holds:** a partial return
made "already restocked?" unanswerable by a status, so it became `restocked_quantity`
against the reservation's own quantity, and the terminal `Restocked` state is reached only
when the last unit is home.

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
- `restock(string $reference, ?int $quantity = null): void` — P5; `$quantity` added by S4
  (null = all of it). Payment is its only caller.
The only sanctioned way another module mutates stock. Order is its first (later) caller.

---

# 6. Events (module-owned, past tense)
`StockItemCreated`, `StockAdjusted`, `StockReserved`, `StockReleased`, `StockCommitted`,
`StockRestocked` (P5), `StockLowStockReached`. Audit records movements; Notification carries low-stock; Search/
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
- [x] Build in phases (scaffold → domain → infra → application/contracts → Offer wiring
      (mirror + buy-box read) → presentation → tests), one commit per phase, suite green,
      human pushes.

---

# 12. What this sprint shipped

## 12.1 Delivered

| Area | Where |
|---|---|
| `StockItem` — the pool, with `available()` computed on read (§2.1, ADR-048) | `Domain/Models/StockItem` |
| `StockMovement` — the append-only ledger, signed deltas (§2.2, ADR-050) | `Domain/Models/StockMovement` |
| `StockReservation` — one row per caller reference (§2.3) | `Domain/Models/StockReservation` |
| Two module enums, no `Enum` suffix (§2.4, ADR-007) | `Domain/Enums/{StockMovementType,ReservationStatus}` |
| Schema: one pool per (org, variant); unsigned counts; `reserved <= on_hand` CHECK | `database/Modules/Inventory/migrations/` |
| Five actions, each one transaction under a row lock (§9) | `Application/Actions/` |
| The on-hand mirror, subscribed to Offer BY CLASS-STRING (§3.1) | `Application/Listeners/MirrorOfferStock` |
| Six events (§6) | `Domain/Events/` |
| Core read port — the buy box's in-stock test (§5.1) | `App\Core\Domain\Contracts\InventoryQueryContract` |
| Core **command** port — the platform's first, for Order (§5.2, ADR-049) | `App\Core\Domain\Contracts\InventoryReservationContract` |
| Low-stock signal, edge-triggered and re-armable (§3.3) | `Application/Actions/Concerns/RecordsMovements` |
| `InventoryPolicy` — read for both audiences, one seller write (§7) | `Presentation/Policies/InventoryPolicy` |
| Seller "Stoğum" + the per-pool movement history (§4) | `Presentation/Filament/Seller/Resources/`, `Presentation/Filament/RelationManagers/` |
| Admin oversight — read only, no lever at all (§4, §7) | `Presentation/Filament/Resources/` |

Inventory imports **no** module — asserted in `LayeringTest`, in both directions.
Everything crossing a boundary is a Core contract or an event resolved by name.

## 12.2 Deliberately absent

**No warehouses and no locations** (ADR-051): one pool per (org, variant), so a seller
with two real depots cannot model them and their availability is the sum. **No cart,
order or checkout** — the reservation primitives ship with no caller but the tests
(ADR-049), which is the point: Order finds them ready instead of inventing them under
deadline. **No expiry sweep for stale holds.** Nothing releases an abandoned
reservation on a timer, because nothing creates one yet; Order brings the lifetime it
needs and the release primitive is already there. **No supplier restocking, no
purchase orders, no stock-take.** **No money anywhere** — this module counts units.

**No seller-facing stock ENTRY** (ADR-048): the count is typed on the Offer form and
mirrored here. **No admin write of any kind** (§7) — see the deviation below for what
enforces that.

## 12.3 Deviations from this document, and why

1. **The `reserved <= on_hand` CHECK constraint is applied on PostgreSQL only.**
   SQLite cannot `ALTER TABLE … ADD CONSTRAINT`, and the test suite runs on SQLite
   `:memory:`. The invariant is enforced where it is actually decided — inside the
   reservation actions, under a row lock — and the constraint is the production
   backstop against a future writer that forgets. Cost: the suite cannot prove the
   database refuses a bad row, so one test is `->skip()` on non-pgsql with the reason
   stated, alongside a driver-independent test that the READ clamps a nonsensical
   projection. `StockItem::available()` never returns a negative on any driver.

2. **The Super Admin bypass reaches `InventoryPolicy::update()`, which otherwise always
   denies.** "Super Admin bypasses every policy" is a platform rule (CLAUDE.md) applied
   by a global `Gate::before`, and carving one ability out of it here would make
   Inventory the single module where the bypass is not what it claims. So the refusal
   is **structural instead**: there is no edit page, no form, no action and no route
   that writes a count, on either panel. An operation that does not exist is a stronger
   guarantee than a permission nobody is meant to spend. Both halves are pinned by
   tests. `update()` stays on the policy so a future edit screen meets a documented
   refusal rather than a missing method.

3. **`available` is not sortable in either panel.** It is computed on read (ADR-048), so
   ordering by it would mean computing it for the whole result set and sorting in PHP.
   The two filters that matter — low stock and out of stock — do the subtraction in SQL
   over the two stored columns instead.

4. **`Presentation/Support/CatalogLabels` is a near-duplicate of Offer's.** Sharing it
   would mean either Core carrying a presentation helper shaped by one module's need, or
   Inventory importing Offer. Two small readers over one Core contract is the cheaper of
   the two wrongs, and the duplication is stated in both docblocks.

## 12.4 Changes this sprint required of other modules

Offer is complete but **not frozen** (its spec §14 anticipated exactly this), so both
changes are inside sanctioned territory. Recorded in the `001_Architecture.md`
amendment log.

| Module | Change | Why it could not be avoided |
|---|---|---|
| Offer | `OfferCreated`, `OfferStockChanged` and `OfferWithdrawn` carry `sellingOrgId` + `sellingOrgUuid` | §10.4's confirmed ruling. Inventory consumes these BLIND, by class-string, and a pool is keyed on the ADR-040 id/uuid pair — without both halves on the payload the mirror would have to look the company up in a module it may not import |
| Offer | `OfferQuery::eligible()` asks `InventoryQueryContract::isAvailable()` instead of filtering `stock_quantity > 0` in SQL | §10.5's confirmed ruling, and the whole point of ADR-048. Functionally identical this sprint (`reserved` is always 0 with no Order), which is why a parity test pins it — plus the case that was previously inexpressible: a variant whose every unit is held for someone's checkout |
| Offer (test support) | `OfferFactory::configure()` dispatches `OfferCreated` | An offer with no stock pool cannot be bought, so every buy-box test would otherwise have had to seed Inventory by hand. Dispatching the real event rather than writing the pool keeps the factory free of an Inventory import and exercises the real mirror |

## 12.5 Follow-ups

1. **The buy-box partial index no longer matches.** `offers_buy_box` is predicated on
   `stock_quantity > 0`, and dropping that filter (§12.4) stops the planner using it. A
   plain index on the same columns would serve the query. Left out deliberately: it is
   a performance change and that phase was a correctness one. Do it before the first
   product page with many sellers.

2. **`StockLowStockReached` has no consumer.** §6 anticipates Notification carrying it;
   the event fires correctly, edge-triggered on the crossing and re-armed when
   availability recovers, and nothing listens. A seller therefore sees the warning in
   the panel (the low-stock filter and the amber badge) and receives no message. The
   listener is Notification's to add and needs no change here.

3. **Availability is read per offer, not batched.** `OfferQuery::eligible()` asks
   Inventory once per candidate row, deliberately un-memoised — a value cached even for
   one request could tell two callers the same unit is free. A product page with many
   sellers therefore issues one query per seller. A batch method on
   `InventoryQueryContract` is the fix when it starts to hurt; correctness first.

4. **No stale-hold expiry.** Nothing sweeps a reservation that was never released. Not a
   defect today (nothing creates one), but it is the first thing Order must bring, and
   until it does a hypothetical caller could strand a seller's stock indefinitely.

5. **The Activity user timeline** — shared with Organization's and Catalog's open
   follow-up, not made worse here.

## 12.6 Later changes to this module

**Payment P5 (2026-08-04) added a fourth reservation verb, `restock`.** Inventory was
never frozen, and this is the change ADR-049's own phasing predicted from the other
direction: the module before its caller shipped three verbs, and the first module that
could refund needed a fourth. It is a Core command-port addition (an ADR-049 amendment),
plus `StockMovementType::Restocked`, `ReservationStatus::Restocked`,
`stock_reservations.restocked_at`, `RestockAction` and a `StockRestocked` event.

**Nothing about the mirror changed**, and that is worth saying: `on_hand` still moves only
from the seller's Offer form and from a reservation verb. A refund is a reservation verb.

**Shipping S4 (2026-08-06) amended that verb again: it takes a QUANTITY.** A buyer may now
return one of the two they bought, so `restock($reference, $quantity)` puts back the units
that came back rather than the whole hold, and `stock_reservations.restocked_quantity`
records the running total. Null still means all of it. The one behavioural consequence
worth knowing about: a partly returned reservation stays `Committed`, so code reading only
the STATUS to decide whether a sale was reversed now gets an answer that is true but
incomplete — read the quantity.

**One consequence is left open, deliberately:** a restock raises Inventory's `on_hand`
without raising the Offer's own `stock_quantity`, exactly as a commit lowers Inventory's
without lowering the Offer's. The two have been allowed to diverge since the commit
primitive shipped — Inventory is the availability authority (ADR-048) and the buy box
reads it, not the Offer column — but a seller editing their stock on the Offer form
overwrites the pool with what they typed. That is a pre-existing seam, not one P5 created;
it is recorded here because a refund makes it reachable in production for the first
time.
