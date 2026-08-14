# Catalog Module Specification

**Status: PHASE 1 COMPLETE (2026-07-28).** Approved 2026-07-27; the decisions in §0
and the rulings in §13 are ratified. **ADR-037 … ADR-041 are recorded** in
[docs/Architecture_Decision_Record.md](../Architecture_Decision_Record.md), with their
entries in the amendment log at the end of
[docs/001_Architecture.md](../001_Architecture.md) (mirroring how the Store module
landed ADR-032…036). This document states each decision AND its cost, per project
culture.

Phase 1 is **not frozen** — Offer will need to reach into it, and freezing a module
the next sprint depends on would mean unfreezing it immediately. See §15 for what
shipped, what is deliberately absent, and the two open follow-ups.

The Catalog is the next major sprint after Store (frozen v1.0). It is large, so this
spec scopes **Phase 1 = the catalog structure only** (§0.6). Offers, inventory, pricing,
orders and the customer-facing product storefront are explicitly out of scope and land
in later, separately-reviewed sprints.

---

# 0. Scope and the decisions

## 0.1 What the Catalog IS

A **shared, platform-level product catalog**: one canonical description of a product
(title, brand, category, attributes, images, variants) that many sellers will later
sell against. This is the Trendyol/Amazon/Hepsiburada model, chosen deliberately over
the per-seller-products (Etsy/Shopify) model.

The Catalog owns four things: the **taxonomy** (categories + attributes), **brands**,
**products**, and **product variants**. It does not own price, stock, the seller↔product
relationship, or anything a buyer transacts on.

## 0.2 What the Catalog is NOT (and where those live)

| Concern | Owner (later sprint) |
|---|---|
| A seller's price/stock/condition for a product | **Offer** module |
| On-hand quantity, reservations | **Inventory** module |
| Cart, checkout, orders | **Order** module |
| Money movement, commission, payout | **Payment** module |
| The buyer-facing product listing on a store page | **Offer** + Storefront (ADR-036) |

A **Product in the Catalog has no price and no stock.** That is the single most
important boundary in this spec: it is what lets one product be sold by many sellers at
different prices without duplication.

## 0.3 ADR-037 — Shared catalog; the seller↔product link is an Offer, never a copy

A Product is platform-owned and shared. A seller never gets their own copy of a product;
they will (in the Offer sprint) create an **Offer** that references a catalog
Product/Variant by uuid. In Phase 1 a seller may only **propose/author** products into
the shared catalog, subject to moderation (§5).

**Cost.** Deduplication and moderation become first-class problems from day one — two
sellers proposing "iPhone 15 128GB" must converge on ONE catalog product, or the shared
catalog degrades into the per-seller mess we rejected. We pay for this with a moderation
lifecycle (§3.1) and GTIN/barcode matching (§3.5), which the simpler model would not
need. We accept the cost because the buyer experience — one product page, many sellers —
is the whole point of the platform.

## 0.4 ADR-038 — Central taxonomy owned by the Category Manager

Categories form a tree owned by the platform (the existing **Category Manager** role,
ADR-013). Each category carries an **attribute schema** (which attributes apply, which
are required, which define variants). A product attaches to a **leaf** category and must
satisfy that category's schema.

**Cost.** Sellers cannot invent categories or attributes; onboarding a genuinely new
kind of product requires a Category Manager to extend the taxonomy first. That is slower
than free-form tagging, and it makes the Category Manager a throughput bottleneck. We
accept it because consistent categories + typed attributes are what make search, filters
and comparison work — the difference between a marketplace and a flea market.

## 0.5 ADR-039 — Variants are first-class; a "simple" product is a one-variant product

A Product has **1..n ProductVariants**. A variant is a unique combination of the
category's **variant-defining** attribute values (e.g. Beden=M, Renk=Kırmızı) and is the
unit that an Offer and Inventory will later reference (SKU). A product with no variant
axes is modelled as a single default variant, never as a special case.

**Cost.** Every read path carries the product→variant join even for single-variant
products, and the authoring UI is more complex than a flat product form. We accept it
because retrofitting variants later (the alternative) would mean rewriting Offer,
Inventory, cart and order lines around a new sellable unit — far more expensive than
carrying one join now. Clothing and footwear are unsellable without variants, so
"later" is not really an option for this market.

## 0.6 Scope of THIS sprint — Phase 1: catalog structure

**In scope (Phase 1):** category tree + attribute schema (Category Manager); brands;
product + variant model; product authoring by sellers with a moderation lifecycle;
product/variant media; the read contract other modules will use; catalog search for
staff/sellers; events.

**Out of scope (later, separately reviewed):** price, stock, offers, the store↔product
listing, the customer-facing storefront product pages, cart/order. **A store cannot
"list" a product in Phase 1** because the store↔product link is an Offer, which does not
exist yet. Phase 1 builds the shelf; Offer puts sellers' goods on it.

**Cost of phasing.** The catalog will exist with no buyer able to see a product for sale
until the Offer sprint ships. Demos in Phase 1 are admin/seller-facing only. We accept
this because building the sellable surface on an unstable catalog schema would be the
more expensive mistake.

## 0.7 ADR-040 — Cross-context references by id/UUID (reaffirms ADR-033)

Catalog imports no other module's models and is imported by none. It references the
**proposing organization by BOTH internal id and uuid** (provenance/moderation only) —
`proposed_by_org_id` for the internal tenancy filter (resolved through the existing Core
`OrganizationAuthorizationContract::organizationIdsForUser()`, which returns internal
ids) and `proposed_by_org_uuid` for public identity. This is the same internal-id-for-
scoping + uuid-for-public split the rest of the codebase uses (how Store references
Organization); it needs no change to the frozen contract, and the internal id never
leaves the app (non-negotiable #7 holds). ADR-033 sanctions references by id/UUID — see
the implementation note in §2.4 for how the pair is used as built. Later
modules (Offer, Inventory, Search, Storefront) reach the Catalog only through the Core
`CatalogQueryContract` (§8) and domain events (§7).

## 0.8 ADR-041 — Catalog enriches the storefront only once products are sellable

Per ADR-036 the storefront is composed via the `StorefrontContributorContract`. Catalog
**registers no storefront contributor in Phase 1**: a store page shows *its* products,
and "its products" means the store's Offers, which do not exist yet. The product-listing
contributor ships with Offer. Phase 1 touches neither Store nor the storefront.

---

# 1. Purpose

## 1.1 Responsibilities
- Own the category tree and per-category attribute schema (Category Manager).
- Own brands.
- Own the canonical Product and its Variants (SKUs), their attributes and media.
- Enforce the product moderation lifecycle (draft → review → published).
- Enforce catalog integrity: leaf-category attach, required attributes, variant
  uniqueness, GTIN/slug uniqueness.
- Publish events and expose a read contract for downstream modules.

## 1.2 Non-responsibilities
Price, stock, offers, cart, orders, payment, commission, the buyer-facing storefront
listing, and the seller's decision to sell a given product (that is an Offer). See §0.2.

## 1.3 Module boundaries
Standard modular monolith (ADR-002): `Domain / Application / Infrastructure /
Presentation`. Cross-module communication is events + Core contracts only; the
`LayeringTest` enforces no cross-module imports.

## 1.4 Relationships
- **Media** — product and variant images use the Media module via the shared `HasMedia`
  trait (private/public disks per `config('marketplace.media.*')`).
- **Localization** — category/attribute/product display strings are localizable; money
  does not appear here (there is no price), so the DECIMAL/integer rules do not bite yet.
- **Store / Organization** — NONE in Phase 1 beyond the uuid provenance reference (§0.7).
- **Later** — Offer (references Product/Variant by uuid), Inventory, Order, Search,
  Storefront.

---

# 2. Domain Model

> Names are proposals. All ids are internal bigint; the public identifier is a UUID
> (non-negotiable #7). All enums are backed, no `Enum` suffix (ADR-007). Money does not
> appear in this module.

## 2.1 `Category` (tree, Category-Manager-owned)
Nested tree (adjacency list + a materialised path or nested-set for descendant queries —
RULE in review). Fields: `uuid`, `parent_id?`, `name` (localizable), `slug` (unique per
level or globally — RULE), `is_active` (lookup-style, ADR-015), position/order. Products
attach to a **leaf** only. Categories carry the attribute schema (§2.3).

## 2.2 `Brand`
`uuid`, `name`, `slug` (unique), `is_active`, optional logo (Media). Product belongs to
one brand (nullable for unbranded/generic).

## 2.3 `Attribute` + `AttributeValue`
- `Attribute`: `uuid`, `code`, `name` (localizable), `type` (see enum §2.6),
  `is_variant_defining` (bool), `is_required` (per category-binding, see below),
  `is_filterable`.
- `AttributeValue`: for `select`-type attributes, the allowed values (`uuid`, `value`,
  localizable label), e.g. Renk → Kırmızı/Mavi.
- **Category ↔ Attribute binding** (`category_attribute` pivot): which attributes apply
  to a category, whether required there, whether variant-defining there. The same
  attribute (e.g. Renk) can be variant-defining in "Giyim" and merely descriptive in
  "Mobilya".

## 2.4 `Product` (aggregate root)
`uuid`, `category_id` (leaf), `brand_id?`, `title` (localizable), `slug` (unique),
`description` (localizable), `gtin`/barcode?, `status` (§2.6), `proposed_by_org_id`
(internal, indexed — tenancy filter via the Core auth contract) + `proposed_by_org_uuid`
(public provenance) (§0.7, ADR-040), timestamps, soft-deletes, **Auditable** (ADR-027 — a
catalog entry is a curated asset; who changed what and why matters). Descriptive
(non-variant) attribute values attach here (`product_attribute_value`). Media: gallery
images.

> **Implementation note (Phase 1) — provenance is the id+uuid PAIR, not the uuid alone.**
> This paragraph and §0.7 originally named only `proposed_by_org_uuid`. Building the
> seller panel showed that insufficient: the seller tenancy wall has to answer "is this
> product mine", and the Core `OrganizationAuthorizationContract` — the *only* way Catalog
> may ask anything about memberships (ADR-040) — answers in **internal ids**. Translating
> those ids to uuids would mean importing an Organization model, which is precisely what
> ADR-040 forbids.
>
> So `Product` carries both, exactly as `stores` carries `organization_id` +
> `organization_uuid`. This is not a new decision: **ADR-033 already states the platform
> rule as "by its id and UUID, with an optional database FK"**, and Store is the shipped
> precedent. The id is the tenancy filter (`Product::scopeProposedByAny()`); the uuid is
> the identifier that leaves the application and rides on events (non-negotiable #7).
> There is still **no `organization()` relation** and no Organization import — the FK is
> integrity-only, and nothing about the boundary changes.

**A Product has no price and no stock.** (ADR-037.)

> **Public buyer surface — ADR-058 (2026-07-31).** Catalog now has one:
> `GET /api/v1/products` (browse/search) and `GET /api/v1/products/{uuid}`
> (product page), anonymous and on the storefront throttle. They return what a
> product IS and **no price** — the storefront overlays that from Offer
> (`POST /offers/prices` for a listing, the existing buy-box route for a page).
>
> The browse shows only **SELLABLE** products: it asks
> `OfferQueryContract::sellableProductUuids()` and narrows its own query, so a
> published product nobody stocks never appears as a card that leads to
> "unavailable". Catalog imports no Offer and gains no price column — the
> composition is a Core contract, and `CatalogBoundaryTest` still holds.
>
> **The listing is sellable-only; the PAGE is published-only**, deliberately: a
> buyer arrives from a bookmark or a search engine long after the last seller ran
> out, and 404ing that page would break every link the platform ever emitted.
>
> Cost: the sellable set is resolved before pagination (otherwise pages are of
> variable size and the total is wrong), so it is a `whereIn` that grows with the
> catalogue. The scaling path — a denormalized `sellable` flag kept current by
> Offer's events — is documented in `PublicProductBrowse` and deliberately not
> built yet.

> **Post-P1 addition — `tax_rate_id` (ADR-056, 2026-07-30, driven by Order).** The Product
> gains a **`tax_rate_id`** → a new managed **`tax_rates`** lookup (admin/Category-Manager-
> configured KDV brackets: name + `rate` DECIMAL + `is_active` — the lookup-table the
> "enum or table?" rule always intended). It is chosen at authoring ("ürün aç") and
> moderated with the rest of the product. A tax bracket is a **classification of the
> product**, not a commercial term — so this does **not** breach the "no price, no stock"
> boundary above; price/stock remain the Offer's/Inventory's. Order reads the rate via
> `CatalogQueryContract` (a `taxRateForProduct` read) and snapshots it onto the order line.
> Catalog is not frozen; this is a normal addition, recorded here and in ADR-056.

## 2.5 `ProductVariant` (the SKU)
`uuid`, `product_id`, `sku` (unique), `barcode?`, the combination of **variant-defining**
attribute values that distinguishes it (`variant_attribute_value` pivot), `is_default`
(the single variant of a simple product), position, media (variant-specific images),
soft-deletes. Every product has ≥1 variant; the combination is unique within a product.

## 2.6 Enums (module-owned, no `Enum` suffix)
- `ProductStatus`: `Draft`, `PendingReview`, `NeedsRevision`, `Published`, `Rejected`,
  `Archived`. **`NeedsRevision`** is the "request a revision" state of §3.1/§5 — a
  moderator sends a product back to the seller with a reason; the seller edits and
  re-submits (`PendingReview` again). (The abbreviated list earlier in review omitted it;
  §3.1/§5 are normative — the six cases here are the truth.)
- `AttributeType`: `Select`, `Text`, `Number`, `Boolean` (extendable).
- (`is_active` for Category/Brand is lookup-style boolean, ADR-015, not a status enum.)
- **This enum is module-owned** at `App\Modules\Catalog\Domain\Enums\ProductStatus`. A
  Sprint-0 placeholder `App\Shared\Enums\ProductStatus` exists (referenced only by a
  transition test); leave it untouched — the Store precedent is that the module owns its
  real status enum and the Shared placeholder is not reused.

## 2.7 The slug registry — every public address (ADR-059)
`slugs` = `{ slug (globally UNIQUE), sluggable_type ('product'|'category'|'brand'),
sluggable_id, is_canonical, timestamps }`.

**Why it exists.** The storefront addresses all three at the ROOT — `/bioderma`,
`/cilt-bakimi`, `/avene-...-krem` — with no type prefix, so they share one namespace
with each other *and* with the storefront's own pages. Three `slug` columns cannot be
unique across three tables; one index over one table can.

- **Turkish-folded** — `Str::slug` already maps İ/ı/ş/ğ/ü/ö/ç → i/i/s/g/u/o/c, pinned by
  test rather than trusted. Numeric suffix on collision.
- **Reserved words** (`config('catalog.slugs.reserved')`) are refused, not rejected: a
  product called "Sepet" gets `sepet-2`. Shadowing `/sepet` would not error — the
  storefront's static route wins and the product becomes silently **unreachable**.
  **A new storefront route must be added to that list BEFORE the frontend ships it.**
- **Stable once issued.** A rename does not move a URL. A deliberate slug change writes a
  new canonical row and keeps the old one `is_canonical = false`, so the resolver reports
  where it moved and the storefront **301**s instead of 404ing.
- **Registered by a model hook** (`HasRegisteredSlug`), not by each action — there are four
  actions, two panels, three factories and a seeder that write a slug, and the cost of
  forgetting one is an entity with no public address at all.
- Hard delete releases the slug; **soft delete parks it** (it stops resolving on its own,
  because the registry loads the model and the default scope hides it).

**`GET /api/v1/resolve/{slug}`** → `{ type, id (uuid), slug, canonical_slug }` or 404. It
is what lets the storefront's single catch-all route render three page types without
three speculative requests per paint, and it **respects publication** — a draft has a
registry row from the moment it is saved, so resolving without a status check would let
anyone enumerate unreleased products by guessing names.

**Every lookup resolves BY SHAPE** (`App\Shared\Support\PublicKey`) and 404s on a miss.
A value that is not uuid-shaped never reaches a `uuid` column: on PostgreSQL that is
`SQLSTATE[22P02]` and a 500, on SQLite a silent false — the platform shipped that bug
three times before the rule became an ADR clause. See ADR-059.

---

# 3. Business Rules

## 3.1 Product moderation lifecycle
`Draft` → (seller submits) → `PendingReview` → (Category Manager) → `Published` |
`Rejected` (with reason; back to Draft on revision, mirroring the KYC document
NeedsRevision pattern). `Published` → `Archived` (delisted; never hard-deleted while
referenced by future Offers). Transitions are actions with events (§7); only the
proposing seller may submit, only a Category Manager/Admin may publish/reject.

**Cost.** The moderation queue is a real operational surface and a Category-Manager
workload; a marketplace that publishes seller products instantly (no review) is faster
but ships duplicates and mis-categorised items. We chose curation (ADR-038).

## 3.2 Category attach (`accepts_products`) & schema conformance
**Amended by ADR-047 (2026-07-29).** A product attaches to any category the Category
Manager has flagged **`accepts_products`** — no longer "a leaf" — and must provide every
attribute that category marks **required**; values must be valid for the attribute type
(a `select` value must be one of its `AttributeValue`s). Rejecting on publish, not on
draft, so authoring can be incremental.

`accepts_products` is a per-category boolean the Category Manager owns. A flagged category
**may still have children** (a product sits at *Makyaj* while *Göz Makyajı* exists under
it); an unflagged one (typically a top-level container) refuses products. Migration set
every existing leaf `true` and every non-leaf `false`, preserving prior behaviour. The
old leaf rule (`categoryIsNotALeaf`) is replaced by the flag check; the "cannot delete /
deactivate a branch with children" guards are unchanged. **Empty categories** (no
products **and** no children) are deletable, so a mis-created category can be removed.

## 3.3 Variant integrity
The set of a product's variant-defining attributes is fixed by its category's schema.
Every product has ≥1 variant; each variant is a **unique** combination of those
attributes' values; `sku` is globally unique. A single-variant product carries one
`is_default` variant with no variant axes.

## 3.4 GTIN / barcode dedup (shared-catalog integrity)
Where a GTIN/barcode exists it is **unique** and is the primary dedup key, so two sellers
proposing the same manufactured product converge on one catalog entry (ADR-037).
Matching/merge policy for near-duplicates without a GTIN is a **documented open
question** for review (§13), not silently resolved.

## 3.5 Slugs & soft-delete
Product and category slugs are unique and stable (SEO). **Since ADR-059 that uniqueness is
GLOBAL and includes brands** — see §2.7 for the registry that enforces it. Archive/soft-delete never
hard-removes a product that Offers will later reference; `Archived` is the terminal
delist state.

---

# 4. Taxonomy management (Category Manager)
CRUD + reordering for the category tree; per-category attribute binding (required /
variant-defining / filterable); attribute + attribute-value management; brand
management. Filament admin resources on the **admin** panel, gated on
`catalog.taxonomy.manage` (Category Manager, Admin, Super Admin).

# 5. Product authoring (seller) + moderation (Category Manager)

The seller has **two entry points** to putting a product up for sale. Only the second is
built in Phase 1; both become "sell" in the Offer sprint:
1. **Select an existing catalog product** and offer it (price/stock). → **Offer, Phase 2.**
2. **"Open a product" (ürün aç)** — the product is not in the catalog, so the seller
   enters its details and submits a **product creation request**. → **Catalog, Phase 1.**

**The product IS the request** (RULED). Unlike a Store Opening Request — where approval
creates a *different* thing (a Store) — approving a product creates nothing new; the
product simply moves to `Published`. So the moderation state lives on the Product's own
`status` (§2.6), and there is **no separate `ProductCreationRequest` entity**. This is
the same NeedsRevision moderation pattern already shipped for KYC documents, and the same
approve/reject shape as the Store Opening Request the admin panel already serves.

- **Seller** (seller panel): create/edit **own** draft products, add media, set
  attributes, generate variants, submit for review. Scoped to `proposed_by_org_uuid` —
  a seller never sees or edits another seller's proposals (the same membership-scoping
  wall the Organization panels use).
- **Category Manager** (admin panel): a review queue — approve (`Published`), reject, or
  request a revision with a reason (`NeedsRevision` → back to the seller); edit/curate
  any product. Admins and Super Admin can moderate too; Category Manager is the role that
  owns the queue day to day (ADR-013 / ADR-038).

# 6. Media
Product gallery + per-variant images via the Media module (`HasMedia`). Public disk for
catalog imagery (it is meant to be seen), served through the CDN-fronted bucket in
production, the local `public` disk on the test box.

`AttachProductMediaAction` takes files in **two shapes**, and callers must be explicit
about which: an `UploadedFile` (the request object, copied and left alone — a Livewire
component still renders from it), or a **path relative to a disk**, in which case the
disk name travels with it as the action's third argument. The upload component in the
seller panel stages the bytes on a disk first and hands over the path, so the two must
name the same disk — `EditProduct::uploadDisk()` is the single expression both use.
The staged copy is *not* preserved: the media library deletes it once the collection
has the bytes, or every upload leaves an orphan on a disk nothing sweeps.

Cost: a path with no disk is ambiguous, and the media library reads it as an absolute
filesystem path rather than refusing it — which is exactly how this shipped broken
once. `tests/Modules/Catalog/Feature/ProductMediaTest.php` exercises the string shape,
not only the object.

# 7. Events (module-owned, past tense)
`CategoryCreated/Updated/Archived`, `AttributeCreated/Updated`, `BrandCreated`,
`ProductDrafted`, `ProductSubmittedForReview`, `ProductPublished`, `ProductRejected`,
`ProductArchived`, `ProductVariantCreated/Updated`. Consumers (later): Search indexing
(index on `ProductPublished`, drop on `Archived`), Offer (react to `ProductPublished` /
`ProductArchived`), Activity/Audit listeners.

# 8. Contracts (Core, for downstream modules)
`App\Core\Domain\Contracts\CatalogQueryContract` — read products, variants, categories
and attributes by id/uuid without importing the Catalog module (mirrors
`StoreQueryContract`). This is how Offer, Inventory, Search and the Storefront read the
catalog. Defined in Core, implemented in Catalog Infrastructure, bound in the provider.

# 9. Policies — roles & capabilities
Registered via `PermissionRegistry` (never hand-written), then `make permissions`,
attached to roles in `RolePermissionSeeder`.
- `catalog.taxonomy.manage` — Category Manager, Admin.
- `catalog.products.moderate` — Category Manager, Admin.
- `catalog.products.author` — Seller (own proposals only, membership-scoped).
- `catalog.products.viewAny` — staff + seller (own), per panel.
Super Admin bypasses via `BasePolicy::before()`. **The Category Manager finally has a
home** — it was reserved in ADR-013 for exactly this module.

# 10. Search
Products are indexed (Scout → OpenSearch, the platform's configured engine) on
`ProductPublished`, removed on `Archived`. Phase 1 exposes catalog search to **staff and
sellers** only; buyer-facing search waits on Offer (you cannot search to buy something
that has no price or seller). Indexing the variant/attribute facets now means filters are
ready when Offer ships.

# 11. Non-negotiables recap (they apply here too)
`declare(strict_types=1)`; no module imports; Domain has no Eloquent/Request/DB facade
and no `cache()/request()/encrypt()`; DTOs suffixed `DTO` in `Domain/DTOs`; roles by name
via `config('marketplace.roles.*')`; policies check permissions not roles; public ids are
UUIDs; no `dd/dump/die`; Audit entries append-only. Money is absent by design (§0.2).

---

# 12. Proposed Application actions (Phase 1)
Taxonomy: `CreateCategory`, `UpdateCategory`, `ReorderCategories`, `ArchiveCategory`,
`BindCategoryAttribute`, `CreateAttribute`, `CreateAttributeValue`, `CreateBrand`.
Products: `DraftProduct`, `UpdateProduct`, `SetProductAttributes`, `GenerateVariants` /
`UpsertVariant`, `AttachProductMedia`, `SubmitProductForReview`, `PublishProduct`,
`RejectProduct`, `ArchiveProduct`. Each owns one transaction; side effects
(search indexing, events) fire in `BaseAction::after()` (after commit).

# 13. Rulings (settled at approval, 2026-07-27)
1. **Category tree storage — RULED: adjacency list + a materialised `path` column**,
   self-owned, no tree package. Writes stay a single parent pointer; descendant reads use
   the path prefix. Cost: the path must be rewritten on a move (rare, and a bounded
   subtree update) — accepted over a package dependency or nested-set write complexity.
2. **Near-duplicate matching — RULED: suggest-on-author + manual merge in moderation;
   NEVER auto-merge.** When a seller authors a product, surface likely existing matches
   (by GTIN, then title/brand) so they can pick one instead of creating a duplicate; the
   Category Manager catches the rest in the queue. Auto-merge is rejected — silently
   fusing two products is unrecoverable and a data-integrity risk.
3. **Initial taxonomy — RULED: a starter seeder** of top-level categories + a few common
   attributes, so the catalog is not empty on day one; the Category Manager extends it
   from there. (Not committed as fixtures a seller depends on — an editable starting set.)
4. **Variant generation UX — RULED: cartesian auto-generate** from the variant-defining
   attribute values the seller selects, with the ability to prune/disable specific
   combinations. The domain stores explicit variants either way; the action generates.
5. **Localization — RULED: tr + en from the start** for title, description, category and
   attribute labels (the platform is bilingual and Localization already exists).
   Retrofitting per-locale columns later is more expensive than carrying them now.
6. **Seller authoring in P1 — RULED: YES.** The "ürün aç" path (§5, entry point 2) is a
   Phase-1 deliverable: seller submits → Category Manager moderates → Published. The
   "select existing + price/stock" path and all selling are Offer (Phase 2). The product
   carries its own moderation status (§5); there is no separate request entity.

# 14. Phasing after this sprint
Phase 2 **Offer** (seller price/stock/condition against a Variant; the store↔product
link; storefront product listing via ADR-036) → Phase 3 **Inventory** → Phase 4
**Order** → Phase 5 **Payment**. Each is a separate spec + architecture review; none may
be built ahead of its approval (CLAUDE.md).

---

## Ratification checklist
- [x] Add ADR-037 … ADR-041 to `docs/Architecture_Decision_Record.md`.
- [x] Add the amendment-log entries to `docs/001_Architecture.md`.
- [x] Add `Catalog` to `app/Modules/README.md` and this file to the modules index.
- [x] Resolve the §13 open questions and fold the rulings into this doc.
- [x] Only then: scaffold `app/Modules/Catalog/*` (Phase 1).

---

# 15. What Phase 1 shipped

## 15.1 Delivered

| Area | Where |
|---|---|
| Category tree (adjacency + materialised `path`, §13.1) | `Domain/Models/Category`, `Infrastructure/Repositories/CategoryRepository` |
| Attribute schema, per-category binding (§2.3) | `Attribute`, `AttributeValue`, `category_attribute` |
| Brands (§2.2) | `Brand` |
| Product + variants, variants first-class (ADR-039) | `Product`, `ProductVariant` |
| Moderation lifecycle (§3.1) | `Domain/Enums/ProductStatus` + the product actions |
| 19 actions (§12) | `Application/Actions/` |
| Policies + permissions (§9) | `Presentation/Policies/`, `CatalogServiceProvider::registerPermissions()` |
| Admin taxonomy + moderation queue | `Presentation/Filament/Resources/` |
| Seller "ürün aç" (§5, entry point 2) | `Presentation/Filament/Seller/Resources/` |
| Media (§6) | `HasMedia` + `AttachProductMediaAction` |
| Search (§10) | `Product::toSearchableArray()`, `SyncProductSearchIndex` |
| Core read contract (§8) | `App\Core\Domain\Contracts\CatalogQueryContract` |
| Starter taxonomy (§13.3) | `Database\Modules\Catalog\Seeders\CatalogTaxonomySeeder` |
| **Slug registry (ADR-059, added 2026-08-03)** — one globally-unique namespace for product/category/brand plus a reserved-word list, because the storefront addresses all three at the ROOT. Turkish-folded, suffixed on collision, **stable once issued**, with a demote-not-delete alias trail so a changed slug 301s instead of 404ing. Registered by a model hook rather than by each action. `GET /resolve/{slug}` turns an address into a type so one catch-all route can render three page types | `Domain/Models/Slug`, `Infrastructure/Registries/SlugRegistry`, `Presentation/Controllers/Api/Storefront/SlugResolverController` |
| **Category + brand public reads (ADR-059)** — `/categories` (tree), `/categories/{slug}`, `/brands`, `/brands/{slug}`, all carrying **sellable** counts through `OfferQueryContract` so a menu and the listing it opens cannot disagree. Counts roll up the materialised path in one pass rather than one query per node | `Infrastructure/Queries/PublicTaxonomyBrowse`, `Presentation/Controllers/Api/Storefront/PublicTaxonomyController` |
| **Cosmetic demo schema (added 2026-08-02)** — Cilt Tipi / Hacim / Kullanım / Menşei bound to the cosmetic branch, values on the two real demo products. All **descriptive**; the two `select` ones are eligible to be variant axes and are explicitly refused, or a face cream would acquire "Hassas × Yüz" SKUs. Goes through `BindCategoryAttributeAction` + `SetProductAttributesAction`, never raw pivot rows, so it produces only data a seller could have produced. Idempotent, and it **skips any product that already carries values** — `SetProductAttributesAction` is a full replacement, and a seeder re-run must not wipe curated content. Not registered in `DatabaseSeeder`; an operator runs it | `Database\Modules\Catalog\Seeders\CosmeticAttributeDemoSeeder` |

Localization is **per-locale columns**, tr + en (§13.5): `title_tr` / `title_en`,
resolved through `Domain/Concerns/HasLocalizedText`. Catalog text is *content* —
authored per record, searched and sorted on — not UI copy, so it does not belong in
the `translations` table.

## 15.2 Deliberately absent

**No price, no stock, no offers** (ADR-037) — asserted by a test over the schema, the
search document and the rendered seller form, not merely documented. **No storefront
contributor** (ADR-041). **No buyer-facing surface at all**: catalog search is exposed
to staff and sellers only (§10), because searching to buy something with no price and
no seller is not a feature.

**No near-duplicate suggestion UI yet.** `ProductRepository::suggestDuplicatesFor()`
implements the §13.2 ruling (suggest, never auto-merge) and is exercised by tests, but
the authoring form does not yet surface its results — GTIN collision, the *exact* half
of that ruling, is enforced at draft time and is what actually prevents duplicates.
Surfacing the fuzzy half is a UI addition, not a model change.

## 15.3 Implementation notes and follow-ups

1. **Provenance is `proposed_by_org_id` + `proposed_by_org_uuid`**, not the uuid alone.
   See the note in §2.4 — the seller tenancy wall needs the id, because the Core
   `OrganizationAuthorizationContract` answers memberships in ids and Catalog may not
   import Organization to translate them (ADR-040). ADR-033 already states id+uuid as
   the platform rule and `stores` already does it.

2. **The seller's organization picker is labelled by id** when a seller belongs to more
   than one company, and hidden entirely when they belong to one (the common case).
   Resolving a legal name would mean importing an Organization model. **Open follow-up:**
   a legal-name label needs either a small addition to the Core contract or a
   Presentation-layer seam — a deliberate decision, not something to smuggle in.

3. **The Activity user timeline is not wired.** A timeline listener belongs to the
   Activity module, which would have to import Catalog's events, and `LayeringTest`
   states nothing imports Catalog. **The identical follow-up is already open for
   Organization** (see its freeze notice), so this is one Activity change, later,
   covering both. Audit needs nothing — `Product` carries the `Auditable` trait.

4. **`PendingReview` cannot be archived.** §3.1 enumerates its transitions exactly, and
   that is coherent: a product must not change underneath the moderator reading it, and
   withdrawing it is a change. A seller who submits by mistake waits for a verdict.

5. Two platform-level defects surfaced by being the first module of its kind, both
   fixed: `App\Shared\Traits\HasMedia`'s conversion chain (Catalog is the first module
   to attach media) and Scout's queue connection in `phpunit.xml` (the first Searchable
   model). Neither was Catalog's own bug.

6. **An empty spec table on a product page was a TAXONOMY gap, not an authoring one**
   (2026-08-02). The public detail surface has always returned `attributes`, and it was
   empty for every product in the catalogue — because the platform held four attributes
   (Renk, Beden, Malzeme, Garanti Süresi), all of them clothing or general, and the
   cosmetic categories were bound to none of them. A seller cannot enter "Cilt Tipi"
   when no category offers the field.

   **The ordering is ADR-038's, and it is the thing to remember when adding attributes
   later:** a Category Manager defines the attribute *on the category*, and only then
   does it appear in the seller's authoring form — `AttributesRelationManager` builds
   the form from `AttributeRepositoryContract::schemaFor($product->category)` minus that
   category's variant axes. `SetProductAttributesAction` enforces the same order from
   the other side: a value for an attribute the category does not bind is refused, not
   stored. Defining an attribute globally does nothing on its own.

   `CosmeticAttributeDemoSeeder` is demo scaffolding for one deployment, not taxonomy —
   the real cosmetic schema is the owner's to curate.

---

# 16. Bulk import — an admin uploads a spreadsheet (ADR-074, 2026-08-10)

An admin uploads an Excel/CSV; each row becomes a **category path + brand + product
+ one default variant + KDV bracket + images**, published, queued and chunked, with
a downloadable per-row failure report. **Catalog only** — no price, no stock: those
are a seller's Offer and arrive in a separate import.

## 16.1 The columns

| Column | Required | Notes |
|---|---|---|
| `baslik` | ✅ | The product title |
| `kategori_yolu` | ✅ | `Erkek > Giyim > Tişört` — created where missing |
| `marka` | | Reused by slug when the name already exists |
| `gtin` | | **The dedup key.** Re-uploading the same barcode UPDATES rather than duplicating |
| `aciklama` | | |
| `kdv` | | `%20`, `20` or `0,20`; unreadable or blank falls back to `kdv-20` |
| `gorsel_url` | | Several separated by `\|`; jpg/png/webp/avif |

The names are **Turkish in both lang files**, deliberately: they are the contract
with a spreadsheet somebody has already filled in, and translating one would break
the template.

## 16.2 It drives the authoring actions and writes no model

`CatalogRowImporter` calls `CreateCategoryAction`, `CreateBrandAction`,
`DraftProductAction`, `UpsertVariantAction`, `SubmitProductForReviewAction` and
`PublishProductAction` — the same path a seller's "ürün aç" takes. **Bypassing them
would skip the moderation lifecycle, the slug registry, the GTIN guard,
`combination_key` and the events other modules consume**, and the result would look
correct in the admin table while being invisible to search, the storefront and Offer.

It also means the import cannot do anything a human could not: `SubmitProductForReviewAction`
still demands a variant and a tax bracket, and `PublishProductAction` still checks the
category's required attributes.

## 16.3 The category path is resolved by NAME WITHIN A PARENT

**Not by global slug, and this is the one thing to preserve if the walk is ever
rewritten.** `categories.slug` is UNIQUE across the whole table, so matching a
segment by slug means that once `Erkek > Ayakkabı` exists, a row for
`Kadın > Ayakkabı` finds the men's category and files every women's shoe under it —
every row succeeds, nothing is reported, and the tree is quietly wrong. A segment is
only meaningful relative to its parent.

**A created leaf gets `accepts_products = true`; an EXISTING category that refuses
products fails the row** rather than being flipped. That flag is a moderated decision
about the shape of the catalogue (ADR-047) and a spreadsheet cell must not overturn
one.

## 16.4 Failure and idempotency

A bad row throws `CatalogImportException` with a **Turkish sentence**, which Filament
records in `failed_import_rows` and offers back as a downloadable report — the batch
carries on. A bad image URL is weaker still: logged and skipped, because a dead link
is not a reason to reject a product whose every other cell is right.

**Re-uploading a corrected file is the intended workflow.** Rows with a GTIN update
in place (title, category, brand, tax; images only when the product has none, so a
re-run does not stack duplicates). Rows without one always create.

## 16.5 The surface, and what it needs to actually run

`/admin` → **Katalog İçe Aktarma** (a page, not a resource: the moderation queue
states "nothing is created from the queue", and a second product table beside it
would be one list too many). Gated on `catalog.products.moderate` — an import
publishes products, which is exactly what that permission means.

**⚠️ THE IMPORT IS QUEUED AND IS INERT WITHOUT A WORKER.** With no `queue:work`
(or Horizon) running, an admin uploads a file, sees a cheerful notification and
nothing whatsoever happens — the same operational truth the scheduler carries for the
sweeps. The page says so on screen rather than leaving it in a run-book.

## 16.6 The image fetch is queued behind the row, and it had to be

**A 20,000-ROW FILE NEVER FINISHED, AND THE REASON WAS ARITHMETIC.** Filament sends
**100 rows per chunk**, and each row carried ~1.6 image urls fetched with
`addMediaFromUrl` — inside the chunk, one after another. That is ~160 sequential
HTTP round trips in a job the worker kills at **120 seconds** (`horizon.php`,
`supervisor-default`). It could not fit: 385 `MaxAttemptsExceeded` failures in
seventeen minutes across two uploads, and a 21,205-row file that reported exactly
**2,000** successes — twenty whole chunks, the ones that happened to finish.

Two symptoms made it hard to read, and both are worth remembering:

- **`failed_import_rows` stayed EMPTY.** The rows never failed validation; the job
  died before it could record anything. A failure report with nothing in it means
  the job was killed, not that the file was bad.
- **The import under-reported what it had written.** Filament increments
  `successful_rows` when a chunk FINISHES, so everything a killed chunk had already
  committed was invisible to its own report — products existed that the import
  swore it had not created.

So the chunk now does **database work only** — category path, brand, product,
variant, tax bracket — and the network work rides on `AttachImportedProductImages`,
one job per product on the **`media`** queue, whose supervisor already allows ten
minutes and 512 MB. Measured on the live server afterwards: 200 brand-new products,
two chunks, **under twenty seconds**, zero failed jobs, with the photographs
arriving behind them over the next ninety seconds.

**The job's timeout is 170s, and that number is not the supervisor's.** The redis
connection releases a reserved job after `retry_after` = **180** seconds, so a job
permitted to run longer gets handed to a SECOND worker while the first is still
downloading — and the product collects two copies of every photograph. A job must
live inside `retry_after`, not inside the supervisor's ceiling.

**A row whose urls are all unusable queues nothing.** The extension check stays with
the row, because a cell holding a PDF should not cost a worker slot to discover.

**And the retry fence is SIX HOURS, not ten minutes** — a second failure the first
fix uncovered. ADR-075 set `getJobRetryUntil()` to ten minutes to stop an unbounded
overnight retry storm, but `retryUntil` is stamped into the payload when a job is
DISPATCHED, so it bounds a chunk's time in the QUEUE rather than its retries. Filament
pushes every chunk at once — 199 of them for a 19,896-row file, completing at roughly
one a minute — so a 19,896-row import stopped dead at row 1,500, exactly ten minutes
in, with the 184 chunks still waiting failed on pickup, untouched, reporting
`attempts = 202` against `maxTries = 3`. That number makes no sense until you notice
Laravel checks the deadline BEFORE it checks attempts.

ADR-075's intent is intact: **`ImportChunk::$tries = 3` is what actually bounds
retries**, and the window is only the outer wall. Six hours outlasts any import this
catalogue can produce and still ends long before anybody finds a storm in the morning.
`OfferImporter` carried the identical ten-minute fence and got the identical fix; a
seller's file is smaller, so it had not hit it yet.

It went unnoticed for a day because chunks were dying of the 120-second worker timeout
first. Each fix reveals the next constraint, which is the ordinary shape of this kind
of work — and the reason both numbers are written down here with what they are
measured against.

## 16.7 A renewed barcode is the same product, and its row is skipped

**A SUPPLIER RE-BARCODES AN ITEM AND THE SHEET ARRIVES CARRYING BOTH.** `Bioderma
Photoderm LEB SPF30 100 ml` came in as `3701129808047` and `...48`; `Babe Stop-Akn`
and `Babe Stop Akn` differ by one hyphen and slug identically. GTIN dedup (§16.4)
cannot see either — the barcodes genuinely differ — so the two rows collide on one
slug. The owner's ruling (2026-08-13): **these are the same product, renewed. Skip
the row.**

It is **reported, not swallowed**, naming both barcodes, because only an admin can
tell a re-barcode from two different products that happen to share a name.

**The collision has two shapes and both are handled.** `SlugRegistry::issue()`
already appends `-2`, but it decides against the registry BEFORE the product row
exists — so two chunks running the same title concurrently both receive the clean
slug and the second insert dies on `products_slug_unique`. A `findBySlug` check
answers the settled case; a caught `UniqueConstraintViolationException` answers the
raced one.

**Neither may reach Filament as a database exception**, and that is the expensive
part of this lesson. An exception that is not `RowImportFailedException` escaping
`resolveRecord()` cannot be recorded against a row: Filament writes the row with an
EMPTY reason and fails the whole JOB, so the queue re-runs the chunk and every
innocent row beside it is reported failed too. One upload produced **117 blank
failures out of 78 real collisions** — and the surplus was neighbours, not
duplicates.

## 16.8 v1 scope

**Deliberately absent, and each for a reason rather than a lack of time:**

- **Variant axes (colour/size) and descriptive attributes.** They need the moderated
  category attribute schema (ADR-038); a spreadsheet cell must not silently create
  schema. v1 gives every product ONE default variant.
- **Offers — price and stock.** A separate seller-facing import; the owner chose
  catalogue-first.
- **Rows targeting a category with required attributes.** They fail publish and are
  reported. v1 creates fresh categories, which have none.
- **Scheduled feed sync** (supplier XML/API). A manual upload is v1.


---

# 17. The storefront's computed strips (ADR-077, ADR-078)

Three read endpoints that rank products by something Catalog does not own, and then
hydrate the ranking into Catalog's own cards:

| Endpoint | Ranked by | Port it asks |
|---|---|---|
| `GET /products/best-sellers` | units sold across paid orders | `OrderQueryContract::bestSellingProductUuids` |
| `GET /products/most-reviewed` | published review count | `ReviewQueryContract::mostReviewedProductUuids` |
| `GET /products/{product}/also-bought` | co-occurrence in paid baskets | `OrderQueryContract::coPurchasedProductUuids` |

## 17.1 Catalog owns the endpoint, never the data

What sold lives in **Order**, what was reviewed lives in **Reviews**, and neither may
build a product card or be imported here. Each endpoint is **one Core call for the
ORDER of things and one hydration for the substance**. `ReviewQueryContract` is the
first port Reviews has ever answered — the module was built reading Catalog and Order
and was asked nothing back until the homepage needed a ranking.

## 17.2 Computed on read, and what that buys

There is no recommendation table: nothing to rebuild, nothing to invalidate, nothing
to go stale against the orders it is derived from. A sale counts the moment it is
paid for. The cost is a live aggregate per request, which the **one-hour cache**
makes affordable — these are anonymous and identical for every visitor, and a
best-seller list an hour behind is indistinguishable from one that is not.

**The cards are cached, not the uuids.** Hydration is the expensive half — the
sellable wall plus the product read — so caching only the ranking would leave it
running on every request.

**Follow-up when volume bites** (not v1, same note as ADR-077): precompute the
rankings into a periodically-rebuilt table and read that in place of the Core call.
The endpoints do not change shape.

## 17.3 A sale is a paid order and nothing else

`Paid` and `Delivered` only. A basket is not a purchase, an abandoned card form is
not a sale, an `Expired` order already gave its stock back (ADR-072), and a
**`Refunded` one does not count** — the money went back, so a product everybody
returned must not lead the homepage.

**Best-sellers count UNITS; also-bought counts BASKETS.** They are different
questions. Ten of one thing in a single basket outsells one of another, so the
ranking sums `quantity`. But "bought together" is a question about how many PEOPLE
paired two things, so co-occurrence counts distinct checkout groups and a single
bulk order cannot invent a trend.

**The unit of "together" is the checkout group, not the order** (ADR-052). A basket
splits into one order per seller, so reading co-purchase from a single order would
find nothing in the most ordinary marketplace basket there is — one bought from two
shops at once.

## 17.4 Rank survives hydration, and the sellable wall still applies

**`whereIn` does not preserve order** — SQL `IN` is a set — so the rank is reapplied
from the position map after the read. Sorting in PHP rather than with a database
`CASE` is deliberate at this size: a strip is twelve rows, and the rule then reads
the same on every driver.

Each endpoint asks for **three times** what it shows. The ranking knows what sold; it
does not know what is still sellable, and a strip that asked for exactly twelve would
come back with four the day eight best-sellers went out of stock. What is not
published or has no live in-stock offer drops out, so the strip is **shorter rather
than wrong** — a card the buyer cannot buy is a card that leads to "unavailable".

## 17.5 Empty is a first-class answer

A shop on its first day has no best sellers and no reviews. `[]` with a **200** is
the contract: the storefront hides a strip that returns nothing and shows it the
moment it fills, so an empty list is the normal early state rather than a failure.
Never a 404, never a placeholder.
