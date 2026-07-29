# Offer Module Specification

**Status: APPROVED 2026-07-29 — building.** The owner approved the design; the §0
decisions and the §13 rulings are ratified. **ADR-042 … ADR-046 are recorded** in
[docs/Architecture_Decision_Record.md](../Architecture_Decision_Record.md), with their
mirror in the amendment log at the end of
[docs/001_Architecture.md](../001_Architecture.md) (the way Store landed ADR-032…036 and
Catalog landed ADR-037…041), and CLAUDE.md narrows the module prohibition to
Inventory/Order/Payment. This document states each decision **and its cost**, per project
culture. Build order: [BUILD_OFFER.md](../../BUILD_OFFER.md).

Offer is the next major sprint after **Catalog Phase 1** (complete, not frozen). It is
what makes the shared catalog *sellable*: it puts a seller's price and stock on a catalog
variant so that one product can be sold by many sellers. **Cart, checkout, orders,
payment and commission are explicitly out of scope** and land in later, separately-
reviewed sprints (Order → Payment). This sprint carries the offer up to — but not
through — the buy box.

---

# 0. Scope and the decisions

## 0.1 What an Offer IS

An **Offer** is a single seller organization's **price and stock for one catalog
`ProductVariant`**. It is the seller↔product link the Catalog deliberately does not own
(ADR-037): the seller never copies the product; they attach an Offer that references the
shared Product/Variant by uuid.

Many offers may target the same variant — that is the whole point. The buyer-facing
product page lists every active offer for the product and features the cheapest in-stock
one (the **buy box**, §5). This is the Trendyol/Amazon/Hepsiburada model the Catalog was
built for.

The Offer module owns exactly this: the **priced, stocked listing**; its lifecycle; the
buy-box computation; and the product-listing **storefront contribution** the Catalog
deferred (ADR-041 → fulfilled here by ADR-046).

## 0.2 What an Offer is NOT (and where those live)

| Concern | Owner |
|---|---|
| Product / variant definition, taxonomy, brand, media | **Catalog** (shipped) |
| On-hand quantity across warehouses, reservations against carts/orders | **Inventory** (later) |
| Cart, checkout, order lines | **Order** (later) |
| Money movement, **commission, payout, settlement** | **Payment / Finance** (later) |
| Tax breakdown / invoicing | **Order** (later) |

**An Offer does not compute commission and does not move money.** It stores the price the
seller sets. What the platform takes and what the seller is paid are settled at Order/
Payment time. This boundary is what keeps the Offer a simple, seller-owned commercial
record.

## 0.3 ADR-042 — An Offer is a priced listing against a variant; one product, many offers

The sellable unit is the **`ProductVariant`** (ADR-039), not the Product. An Offer
references exactly one variant by uuid and carries the seller org's price and stock for
it. A seller has **at most one active offer per variant** (§3.2) — editing price or stock
updates that offer, never forks a second one.

**Cost.** Every buyer read that shows "a product for sale" must fan a variant out to its
competing offers and pick a winner at read time (§5); there is no single "product price."
We accept it because per-variant, per-seller pricing with no duplication of the catalog is
the defining behaviour of a multi-vendor marketplace — the reason the Catalog was built
shared in the first place.

## 0.4 ADR-043 — Stock lives on the Offer as a simple quantity this sprint

The Offer carries a single integer `stock_quantity` the seller sets ("elimde X adet
var"). The buy box reads it for in-stock/out-of-stock; `OutOfStock` is **derived** from
`stock_quantity = 0`, never a stored status (§3.3). When the **Inventory** sprint ships,
it becomes the authority for on-hand quantity and reservations, and the Offer's number is
migrated to / derived from Inventory rather than set directly.

**Cost.** For one sprint, stock is a naïve counter with no reservation semantics: two
buyers could each see "1 in stock" before either checks out. That race is harmless now
because **there is no checkout this sprint** — nothing decrements stock yet. We accept the
temporary simplicity so the buy box can honestly say "tükendi" today, and we pay the
migration cost to Inventory later, deliberately, once reservations actually matter.

## 0.5 ADR-044 — No moderation on offers; free and instant-live, admin reactive only

Unlike product authoring (Catalog §3.1, a full draft→review→published lifecycle), an
offer goes **live the moment the seller creates or edits it**. The product was already
moderated; price and stock are the seller's commercial freedom. Basic validation still
applies (price > 0, `list_price ≥ price`, published product, in-scope org) — that is
validation, not moderation. Admin oversight is **reactive**: an admin may `Suspend` an
individual offer (§3.1), the same shape as Store/User suspension.

**Cost.** A seller can list an absurd or abusive price and it is visible until someone
reacts; there is no pre-publication gate. We accept it because per-offer moderation does
not scale (a catalog of thousands of products × many sellers each re-priced daily would
drown the Category Manager) and it would kill the "list and sell instantly" value that
makes sellers show up. Reactive suspension + later automated price-sanity rules are the
proportionate control.

## 0.6 ADR-045 — The buy box is computed, never stored

There is no persisted "winning offer" column and no ranking job. The featured offer for a
product is computed at read time: **the cheapest offer that is `Active` and in stock**,
ties broken by earliest `created_at` (a stable, explainable rule). Everything else is
listed below it, ascending by price; paused/out-of-stock/suspended offers are excluded
from the buy box (and either shown greyed or hidden, §5).

**Cost.** Every product-page read recomputes the winner instead of reading a column, and
a heavier future buy-box (seller performance, shipping speed) would need real ranking
infrastructure we are choosing not to build now. We accept it because a stored winner
would need invalidation on every price/stock/status change across every competing offer —
a cache-coherency problem far more expensive than a cheap indexed "min price where active
and in stock" query, and we have no seller-performance data to rank on yet anyway.

## 0.7 ADR-046 — Offer ships the storefront product-listing contributor (fulfils ADR-041)

Catalog registered **no** storefront contributor (ADR-041) because "a store's products"
means its offers, which did not exist. Offer now ships that `StorefrontContributorContract`
implementation (ADR-036): it enriches a store's composed public read surface with the
store's active offers (product summary + variant + price + buy-box position). Store still
depends on nothing; it composes Offer's contribution through the existing
`StorefrontRegistry`, exactly as designed.

Offer imports **no** other module. It reads Catalog through the Core `CatalogQueryContract`
(existence/published/variant→product) **plus a new `CatalogBrowseContract`** (§8) for the
seller "select a product to sell" search; it resolves seller tenancy through the existing
`OrganizationAuthorizationContract::organizationIdsForUser()`; and it references the store
through `StoreQueryContract`. All cross-context references are **id (internal, tenancy) +
uuid (public)**, reaffirming ADR-040/033. Downstream (Order, Inventory, Search) reaches
Offer only through the Core `OfferQueryContract` (§8) and domain events (§7).

## 0.8 Scope of THIS sprint

**In scope:** the Offer model + lifecycle; seller offer authoring via **two converging
entry points** — (a) *select an existing published catalog product/variant and price it*,
(b) the Catalog "ürün aç" flow (already shipped) whose product, once published, becomes
offerable; the computed buy box; the public "product + its offers" read surface; the
storefront product-listing contributor (ADR-046); seller + admin Filament surfaces;
events; the Core `OfferQueryContract`; and the Catalog-side `CatalogBrowseContract`
addition that powers the seller's catalog search.

**Out of scope (later, separately reviewed):** cart, checkout, orders, order lines,
payment, commission, payout, tax breakdown/invoicing, multi-warehouse inventory and
reservations, and any seller-performance-weighted buy box. **A buyer can SEE offers this
sprint but cannot BUY** — "sepete ekle" arrives with Order.

**Cost of phasing.** The marketplace will show priced products with a buy box but no
working checkout until Order ships; demos end at the product page. We accept it because
building checkout on an offer schema that has not yet met real buyer reads would be the
more expensive mistake — the same discipline that phased Catalog before Offer.

---

# 1. Purpose

## 1.1 Responsibilities
- Own the `Offer`: a seller org's price (+ optional list price) and stock for one variant.
- Enforce offer integrity: one active offer per (org, variant); offers only on published
  products; price/list-price validation; org-in-scope.
- Own the offer lifecycle (active / paused / withdrawn / suspended) and its events.
- Compute the buy box (cheapest active in-stock offer) at read time.
- Expose the public "product + its offers" read surface and the storefront product-
  listing contributor (ADR-046).
- Publish events and expose the Core `OfferQueryContract` for downstream modules.

## 1.2 Non-responsibilities
Product/variant/taxonomy/brand/media (Catalog); on-hand/reservations (Inventory);
cart/checkout/orders (Order); money movement/commission/payout/tax (Payment/Finance).
See §0.2.

## 1.3 Module boundaries
Standard modular monolith (ADR-002): `Domain / Application / Infrastructure /
Presentation`. Cross-module communication is events + Core contracts only; `LayeringTest`
enforces no cross-module imports. Offer imports neither Catalog, Organization nor Store
models.

## 1.4 Relationships
- **Catalog** — read-only, via `CatalogQueryContract` (validate a variant is published)
  and the new `CatalogBrowseContract` (seller product search). References Product/Variant
  by uuid.
- **Organization** — tenancy only, via `OrganizationAuthorizationContract::
  organizationIdsForUser()` (internal ids) for the seller-panel scope wall; the org is
  stored as `selling_org_id` (internal) + `selling_org_uuid` (public), the ADR-040 pair.
- **Store** — via `StoreQueryContract`; the offer records the org's `store_uuid` so the
  storefront contributor can attribute it. Only Active stores surface offers publicly.
- **Localization** — price is money (integer minor units); APIs format it as a decimal
  string (non-negotiable #6). Currency references the `Currency` model.
- **Later** — Inventory (owns stock), Order (consumes offers), Search (indexes offers for
  buyer search), Payment (commission/settlement).

---

# 2. Domain Model

## 2.1 `Offer` (aggregate root)

| Field | Notes |
|---|---|
| `id` | internal, never leaves the app (non-negotiable #7) |
| `uuid` | public identifier |
| `variant_uuid` | the catalog `ProductVariant` this prices (ADR-039); validated via `CatalogQueryContract` |
| `product_uuid` | denormalized parent product (captured at create via `productUuidForVariant`) — buy-box grouping without a per-read catalog call |
| `selling_org_id` | internal, tenancy filter (resolved via `organizationIdsForUser()`) — ADR-040 |
| `selling_org_uuid` | public org identity |
| `store_uuid` | the org's store (storefront attribution) |
| `price_minor` | **integer minor units** (non-negotiable #6); the consumer price, KDV **included** |
| `list_price_minor` | nullable; the struck-through "market/list price" for discount display; must be `≥ price_minor` |
| `currency_id` | → `Currency` model; defaults to platform default (₺). Single-currency in practice this sprint, but stored for future multi-currency |
| `stock_quantity` | integer ≥ 0 (ADR-043); `0` ⇒ out of stock (derived, §3.3) |
| `status` | `OfferStatus` (§2.2) |
| timestamps | `created_at` (buy-box tie-breaker), `updated_at`, soft-delete for `Withdrawn` |

**No condition field** (owner decision, 2026-07-29): this sprint's marketplace is
new-goods only. A `condition` enum can be added later additively if a used/refurbished
market is needed — it is deliberately absent, not forgotten.

## 2.2 `OfferStatus` (module-owned enum, no `Enum` suffix — ADR-007)
- `Active` — live and eligible for the buy box (subject to stock).
- `Paused` — seller temporarily hides it; excluded from the buy box, retained.
- `Withdrawn` — seller removes it (soft-deleted); terminal from the seller's side.
- `Suspended` — admin oversight action; excluded everywhere until reinstated.

`OutOfStock` is **not** a case (ADR-045/043) — it is `Active && stock_quantity = 0`,
computed. Storing it would create a second source of truth for the same fact.

---

# 3. Business Rules

## 3.1 Offer lifecycle
`create → Active` immediately (no moderation, ADR-044). Seller transitions: `Active ⇄
Paused`, `→ Withdrawn` (terminal). Admin transitions: `→ Suspended` / `Suspended →
Active` (reinstate), storing `status_before_suspension` to restore the exact prior state
(the Store suspension pattern). Every transition is a `BaseAction` emitting an event (§7).

## 3.2 Uniqueness — one active offer per (org, variant)
A unique constraint over `(selling_org_id, variant_uuid)` among non-withdrawn offers. A
seller re-pricing a variant edits the existing offer; they cannot hold two competing
offers for the same variant (owner decision, 2026-07-29). A withdrawn offer does not block
creating a fresh one later.

## 3.3 Stock & out-of-stock derivation
`stock_quantity` is a non-negative integer the seller sets. Out-of-stock is derived
(`= 0`), never stored. The buy box excludes out-of-stock offers; the product page may
still list them (greyed) so a buyer sees the seller exists. Nothing decrements stock this
sprint (no checkout).

## 3.4 Offer preconditions & validation
- The variant must exist and its product must be **Published** (`CatalogQueryContract::
  isProductPublished` on `productUuidForVariant`). Draft/rejected/archived ⇒ rejected.
- `price_minor > 0`; `list_price_minor`, if present, `≥ price_minor`.
- The selling org must be in the actor's scope (`organizationIdsForUser`) and have an
  **Active store** (`StoreQueryContract`). No store, no offer.

## 3.5 Product-lifecycle cascade
Offer reacts to Catalog events: on `ProductArchived` (or unpublish), all offers for that
product are auto-`Paused` (not withdrawn — the product may return). This keeps a
de-published product from being sold through a stale offer, without destroying the
seller's price. On re-publish, paused-by-cascade offers are reactivated.

---

# 4. Seller authoring — two converging entry points

Both paths end at the same place: an active offer on a published variant.

1. **Select from the catalog (new this sprint).** The seller searches the **shared,
   published** catalog (by text / category / brand), picks a product and a variant, and
   sets price + stock. Backed by the Core `CatalogBrowseContract` (§8) over Catalog's
   existing search index — Offer never imports Catalog. This is the "sistemde var olan
   üründen seç ve sat" path the owner asked for.
2. **"Ürün aç" then offer (already shipped).** The seller authors a new product (Catalog
   §5); once the Category Manager publishes it, the seller offers it via path 1's price/
   stock step. Authoring and pricing stay separate concerns (Catalog moderates the
   product; Offer prices it).

A seller's own product does **not** auto-create an offer — publishing a product and
deciding to sell it at a price are distinct acts (a seller might author a product for the
catalog and price it later, or not at all).

---

# 5. The buy box (product page read)

Computed, not stored (ADR-045). For a product:
- **Eligible** offers = `Active`, `stock_quantity > 0`, on an **Active** store.
- **Featured** = cheapest eligible offer; ties → earliest `created_at`.
- **Other sellers** = remaining eligible offers, ascending by price.
- Out-of-stock / paused offers may be listed greyed (never featured); suspended/withdrawn
  never appear.

The public read surface exposes the product summary (from Catalog via contract), the
featured offer, the offer count, and the seller list. Money is rendered as a decimal
string; the internal `price_minor` never leaves.

---

# 6. Storefront contribution (ADR-046, fulfilling ADR-041)

Offer registers a `StorefrontContributorContract` implementation with the
`StorefrontRegistry`. Given a `StorefrontContext` (a store uuid), it returns that store's
active offers (product summary + variant + price + in/out of stock), merged by the
`PublicStorefrontAssembler` under its `extensions` key. A throwing contributor is dropped,
not fatal (existing ADR-036 behaviour). Store depends on Offer for **nothing**; the flow
is entirely Store composing Offer.

---

# 7. Events (module-owned, past tense)
`OfferCreated`, `OfferPriceChanged`, `OfferStockChanged`, `OfferPaused`, `OfferResumed`,
`OfferWithdrawn`, `OfferSuspended`, `OfferReinstated`. Downstream: Search reindexes on
create/price/stock/status; a future Order validates against `OfferQueryContract`;
Activity/Audit listeners record seller/admin actions (shared user-timeline follow-up).

---

# 8. Contracts (Core)

## 8.1 `OfferQueryContract` (new — Offer implements)
The read port for Order / Storefront / Search, so they never import Offer:
- `offerExists(string $offerUuid): bool`
- `activeOffersForProduct(string $productUuid): array` (buy-box list, ordered)
- `featuredOfferForProduct(string $productUuid): ?array` (the winner, or null)
- `activeOffersForVariant(string $variantUuid): array`
- `offersForStore(string $storeUuid): array` (storefront contribution)

Returns plain arrays/DTOs (uuid, price as minor int + currency, stock flag), never Eloquent
models — the boundary rule the other Core contracts follow.

## 8.2 `CatalogBrowseContract` (new — **Catalog** implements; Catalog is not frozen)
The seller "select a product to sell" search the Offer panel needs, which
`CatalogQueryContract` (deliberately minimal existence checks) does not provide:
- `searchPublishedProducts(string $query, ?string $categoryUuid, ?string $brandUuid, int $page): array`
- `variantsForProduct(string $productUuid): array` (uuid + attribute labels, for the pick step)

This is the one **Catalog** change this sprint. It reads Catalog's existing search index;
it adds a read contract only (no schema change), consistent with Catalog Phase 1 being
left unfrozen precisely so Offer could reach in.

---

# 9. Policies — roles & capabilities
- **Seller side** — the `OrganizationAuthorizationContract` capability check (Owner +
  Manager manage offers; the existing capability matrix), scoped to the acting org.
  Register an `OfferManage` capability if the matrix needs a distinct one.
- **Admin side** — `offer.*` permissions (`view_any`, `view`, `suspend`, `reinstate`) via
  `PermissionRegistry`, attached in `RolePermissionSeeder`. Oversight roles (Admin,
  Support) see offers; suspend/reinstate is Admin. Follows Store's admin-permission shape.
- `OfferPolicy` gates seller (contract) + admin (permissions); `owns()` overridden to the
  org-scope check (BasePolicy defaults to false).

---

# 10. Search
This sprint indexes offers so the **buyer-facing** search the Catalog deferred (Catalog
§10 — "searching to buy something with no price and no seller makes no sense") becomes
possible: a product is searchable-to-buy once it has an active in-stock offer, filterable
by price. Full buyer search UX (facets, relevance tuning) can be its own later refinement;
this sprint wires the index and the price/in-stock filter.

---

# 11. Non-negotiables recap (they apply here too)
`declare(strict_types=1)`; **money = integer minor units**, APIs format as decimal string
(non-negotiable #6); public ids are UUIDs, internal id never leaves (#7); Domain imports
no Eloquent/Request/DB facade and no `cache()/request()/encrypt()` (#3, ADR-019); no
`dd/dump/die`; roles by name via `config('marketplace.roles.*')`; policies check
permissions not roles; DTOs carry the `DTO` suffix in `Domain/DTOs/` (ADR-021); side
effects in `BaseAction::after()` (after commit).

---

# 12. Proposed Application actions (this sprint)
`CreateOfferAction`, `UpdateOfferPriceAction`, `UpdateOfferStockAction`, `PauseOfferAction`,
`ResumeOfferAction`, `WithdrawOfferAction` (seller); `SuspendOfferAction`,
`ReinstateOfferAction` (admin); `CascadePauseOffersOnProductArchivedAction` (event
listener). Each: one transaction, `handle()`, verb+noun, event in `after()`.

---

# 13. Rulings (settled at approval, 2026-07-29)
1. **Currency** — single platform default (₺) stored per offer for future multi-currency;
   **no** per-offer currency choice in the seller UI this sprint. **Confirmed by owner.**
2. **List price** — optional; purely a display/discount field; no campaign engine.
3. **Product-archived cascade** — auto-`Paused` (recoverable), not withdrawn (§3.5).
4. **Store requirement** — an offer requires the org to have an **Active** store (§3.4);
   a paused/closed store hides its offers from the buy box.
5. **Buyer search depth** — index + price/in-stock filter this sprint; richer facets later.
6. **No condition field** — new-goods only; a condition enum is added later additively if a
   used/refurbished market is ever needed. **Confirmed by owner.**

---

# 14. Phasing after this sprint
Offer (this) → **Inventory** (on-hand + reservations; becomes stock authority, ADR-043) →
**Order** (cart, checkout, order lines, tax; consumes `OfferQueryContract`) → **Payment/
Finance** (commission, payout, settlement). Each is its own spec + architecture review.

## Ratification checklist
- [x] Record ADR-042…046 in the ADR record + amendment log (2026-07-29).
- [x] Confirm the §13 rulings (currency ₺ single, no condition field — owner-confirmed).
- [x] Narrow the CLAUDE.md module prohibition to Inventory/Order/Payment.
- [ ] Build in phases (scaffold → domain → infra → application → presentation → contracts/
      storefront → search → tests), one commit per phase, suite green, human pushes.
