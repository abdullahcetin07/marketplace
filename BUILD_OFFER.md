# Work order — build the Offer module (seller offers + buy box + storefront)

**Disposable. `git rm` when done.** For the server-side session (has `vendor/`, DB, tests).
Owner-approved 2026-07-29. The authoritative design is **[docs/modules/Offer.md](docs/modules/Offer.md)** —
read it fully before writing code; this file is only the build order and the guardrails.

Build **incrementally, one commit per phase**, keep the suite green (`php artisan test`),
push after each phase-group (human pushes — no creds on the box). If anything in this
order contradicts the docs chain (CLAUDE.md → ADR → Architecture → …), **STOP and report**
(ADR-018) — do not pick a side.

## What Offer is (one paragraph)
An **Offer** = one seller org's **price (+ optional list price) and stock** for one catalog
**`ProductVariant`**. One product, many offers; the cheapest active in-stock offer is the
computed buy box. No offer moderation (live on save; admin reactive suspend only). Stock is
a simple counter on the offer this sprint (Inventory later is the authority). **No cart,
order, payment, commission, tax, or condition field.** See Offer.md §0.

## Hard rules (build fails otherwise)
- **Imports nothing.** No `use App\Modules\Catalog|Organization|Store\...`. Read Catalog via
  `CatalogQueryContract` + the new `CatalogBrowseContract`; org tenancy via
  `OrganizationAuthorizationContract::organizationIdsForUser()`; store via `StoreQueryContract`.
  `LayeringTest` must stay green.
- **Money = integer minor units** (`price_minor`, `list_price_minor`); APIs format as decimal
  strings. Use the `Currency` model; default ₺; store `currency_id` but no per-offer currency
  UI this sprint (ruling §13.1).
- **Public ids are UUIDs; internal `id` never leaves.** Cross-context refs are the ADR-040
  pair: `selling_org_id` (internal, tenancy filter) + `selling_org_uuid` + `variant_uuid` +
  `product_uuid` + `store_uuid`.
- `declare(strict_types=1)`; Domain imports no Eloquent/Request/DB facade, no
  `cache()/request()/encrypt()` (ADR-019); DTOs `...DTO` in `Domain/DTOs/` (ADR-021); no
  `dd/dump/die`; side effects in `BaseAction::after()`; roles by name via
  `config('marketplace.roles.*')`; policies check permissions not roles.
- **Buy box is computed, never stored** (ADR-045). **`OutOfStock` is derived** from
  `stock_quantity = 0`, not a stored status (ADR-043).
- **One active offer per `(selling_org_id, variant_uuid)`** among non-withdrawn offers
  (DB unique + validated). Offer only on a **Published** product with an **Active** store.

## Build phases (one commit each; group the pushes)

**P0 — ratify + scaffold.** Record **ADR-042…046** in
[docs/Architecture_Decision_Record.md](docs/Architecture_Decision_Record.md) AND mirror them
in the amendment log at the end of [docs/001_Architecture.md](docs/001_Architecture.md)
(same as Catalog's ADR-037…041 landing). Scaffold `app/Modules/Offer/{Domain,Application,
Infrastructure,Presentation}`, service provider, `config/offer.php` if needed, module README,
register in the modules index. Green.

**P1 — domain.** `Offer` model, `OfferStatus` enum (Active/Paused/Withdrawn/Suspended — no
`Enum` suffix, no `OutOfStock`), DTOs (`CreateOfferDTO`, `UpdateOfferPriceDTO`,
`UpdateOfferStockDTO`), events (Offer.md §7), and the **Core** `OfferQueryContract` interface
(§8.1). No price/stock logic in Domain that reaches infrastructure.

**P2 — infra.** Migration (fields per §2.1, unique index per §3.2, indexes for the buy-box
"min price where active & in stock" query), repository (declare eager loads on `$with`,
strict mode), factory, and the `OfferQuery` implementation of `OfferQueryContract`. **Also
implement `CatalogBrowseContract`** (§8.2) **inside the Catalog module** (Catalog is not
frozen — this is the one sanctioned Catalog change: a read contract over its existing search
index, no schema change) and its Core interface. Green.

**P3 — application + policy.** Actions per §12 (create / update price / update stock / pause /
resume / withdraw / suspend / reinstate / cascade-pause-on-`ProductArchived`), each one
transaction + event in `after()`. `OfferPolicy` (seller via the org capability —
Owner+Manager, `owns()` overridden to the org-scope check; admin via `offer.*` perms
registered through `PermissionRegistry`, attached in `RolePermissionSeeder`). Validation:
price > 0, `list_price ≥ price`, published product, active in-scope store. Green.

**P4 — presentation (Filament).** Seller panel: **"Tekliflerim"** (list/edit price+stock/
pause/resume/withdraw, tenancy-scoped via `organizationIdsForUser()`) + a **"Katalogdan seç
& sat"** create flow (search published catalog through `CatalogBrowseContract` → pick
product+variant → set price/stock). Admin panel: **offer oversight** (list/view/suspend/
reinstate, gated on `offer.*`). Per-panel registration, delegate to Actions, policy-gated.
Green.

**P5 — public surface + storefront.** The public **"product + its offers"** read surface
(featured offer + seller list, money as decimal string, computed buy box per §5) with its
controller/route/resource, following the Store public-surface shape (unauth, throttled,
Active-store only, uuid allow-list). Register the **`StorefrontContributorContract`**
implementation (ADR-046) with the `StorefrontRegistry` so a store page surfaces its active
offers. Green.

**P6 — search.** Index offers so buyer-facing search becomes possible (product searchable-
to-buy once it has an active in-stock offer; price + in-stock filter). Wire the index and the
filter; richer facets are a later refinement (§10). Green.

**P7 — hardening.** Tests + docs + sweep:
- Boundary test: **no price/stock/commission escapes to Catalog**; Offer imports no module
  (extend `LayeringTest`/a schema test).
- One active offer per (org, variant); offer refused on unpublished product / no active store.
- Buy box: cheapest active in-stock wins, ties by `created_at`; paused/out-of-stock/suspended
  excluded; withdrawn never appears.
- Seller tenancy isolation (a second seller cannot see/edit the first's offers); admin
  suspend/reinstate restores prior state.
- `ProductArchived` cascade pauses offers; re-publish reactivates.
- Money rendered as decimal string; internal ids never in payloads.
- Update Offer.md status to reflect what shipped (a §15-style "delivered / deliberately
  absent / follow-ups" section, like Catalog).

## Notes / choices already made (don't re-litigate — report if you must deviate)
- **Currency** single ₺, stored per offer, no UI choice (§13.1).
- **List price** optional display-only; no campaign engine.
- **Product-archived** → auto-**Paused** (recoverable), not withdrawn (§3.5).
- **Store required** = the org must have an **Active** store to offer (§3.4).
- **No condition field** — new-goods only; add later additively if ever needed.
- Mail is `log` on this box; nothing here sends mail. If a test needs a verified user, mark
  verified directly (as done elsewhere).

## Finish
`git rm BUILD_OFFER.md`, commit. Report the test line (`php artisan test` count), the ADR
entries added, and a short note on what a live seller/admin/product-page walk-through would
show (or drive Livewire if no browser, as with user-management).
