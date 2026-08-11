# BUILD — Seller Offer Feed (Price + Stock Ingress) · ADR-076

**Status:** Ready. Decision: **ADR-076** (`docs/Architecture_Decision_Record.md`),
amendment log #15. Full design:
`docs/superpowers/specs/2026-08-11-seller-offer-feed-design.md` — read it first; this
work order is the build sequence, the spec is the *why*.

Sellers enter offers one Filament form at a time; a real store is thousands of SKUs with
daily price/stock changes. This adds a **seller offer feed**: **one upsert action**, two
adapters — a **token-authed REST API** (the priority) and a **seller-panel CSV import**.

Build **P1 → P4 in order.** Everything runs in Docker; `make check` (Pint + PHPStan +
tests) must pass before this is done.

## Non-negotiables (this feature will be tested against them)

1. **The feed DRIVES the existing offer actions — it never writes the `Offer` model
   directly.** `CreateOfferAction` / `UpdateOfferStockAction` emit `OfferCreated /
   OfferStockChanged`, which **Inventory mirrors on-hand from** (ADR-048) and search
   consumes. A model write would be an offer that is right in the table and invisible to
   availability and search. This is the load-bearing rule (same as ADR-074's importer).
2. **Offer imports NO module.** GTIN→variant reads go through Core `CatalogQueryContract`
   only. `LayeringTest` fails the build on any cross-module import.
3. **No price/stock leaks into Catalog.** The new contract method returns a uuid string.
   `CatalogBoundaryTest` stays green.
4. **Money is integer minor units.** Price arrives as a decimal string, is converted to
   kuruş at the request boundary, never a float (ADR-005).
5. A row/item that fails is **reported and skipped**, never fails its neighbours or the
   request.

---

## P1 — The brain + the Core contract

### P1.1 Core `CatalogQueryContract` gains one read-only method
`app/Core/Domain/Contracts/CatalogQueryContract.php`:
```php
/** The published product's default variant uuid for this GTIN, or null. */
public function publishedVariantUuidForGtin(string $gtin): ?string;
```
Implement it in Catalog's binding of that contract (GTIN is already `UNIQUE` on
`products`). Return null when the GTIN is unknown **or** the product is not published.
v1 products have one default variant (ADR-074), so return that variant's uuid.

### P1.2 `OfferFeedException`
`app/Modules/Offer/Domain/Exceptions/OfferFeedException.php` (extends the module
exception base, `$reportable = false`). Named constructors for the machine reasons:
`productNotInCatalog(string $gtin)`, `offerNotFound(string $gtin)`,
`invalidPrice`, `invalidStock`, `listPriceBelowPrice`.

### P1.3 `SyncSellerOfferAction` (upsert by seller org + variant)
`app/Modules/Offer/Application/Actions/SyncSellerOfferAction.php`, extends `BaseAction`,
one `handle()`, one transaction, **one item per invocation** (the batch loop lives in the
adapters, so one item's failure never rolls back another's).

Inputs (a DTO — `SyncOfferDTO` in `Offer/Domain/DTOs/`): the **selling org** (id + uuid)
and **store uuid** (resolved by the caller from the authenticated seller, exactly as the
seller panel's `CreateOffer` page resolves them today), plus `gtin`, `priceMinor`,
`stockQuantity`, optional `listPriceMinor`.

Logic:
1. `variantUuid = CatalogQueryContract::publishedVariantUuidForGtin($gtin)`; null →
   throw `OfferFeedException::productNotInCatalog`.
2. Find this org's existing offer for that variant via `OfferRepositoryContract` (add a
   `findBySellerAndVariant(int $sellingOrgId, string $variantUuid): ?Offer` finder if one
   does not already exist).
3. **Upsert:**
   - none → `CreateOfferAction` (price + stock + listPrice).
   - exists → `UpdateOfferPriceAction` iff price/listPrice changed **and/or**
     `UpdateOfferStockAction` iff stock changed. Unchanged fields are skipped so a
     no-change re-send emits nothing.
4. Return an outcome enum/DTO: `Created | Updated | Unchanged` (the reason on failure
   rides the thrown exception, caught by the adapter).

**Two narrower siblings (or one action + a mode):**
- **Stock-only** (`offers/stock`): steps 1–2 + `UpdateOfferStockAction`. **Requires an
  existing offer** (no price to create one with) → no offer ⇒
  `OfferFeedException::offerNotFound`.
- **Withdraw** (`offers/withdraw`): resolve variant, find the offer, `WithdrawOfferAction`.

### P1 tests
Feature: create path (offer created + `OfferCreated` emitted + Inventory on-hand
mirrors), update path (price/stock updated, unchanged emits nothing), reject
(unknown/unpublished GTIN → `productNotInCatalog`, no offer, no event), idempotency (same
input twice → `Unchanged`), stock-only-without-offer → `offerNotFound`.

---

## P2 — REST API (the priority)

Routes under `routes/api.php`, group `prefix('seller/offers')`, middleware
`['auth:sanctum', 'throttle:api']`, plus the seller-actor scoping the existing seller API
routes use. Controllers in
`app/Modules/Offer/Presentation/Controllers/Api/Seller/`.

### P2.1 Auth & token management
- **Per-seller Sanctum bearer tokens.** Sanctum is already installed and drives the
  existing `auth:sanctum` API — reuse it. The token authenticates as the seller user; the
  **acting selling org + store** are resolved from that user the same way the seller
  Filament panel does (single acting org in v1).
- A seller-panel page to **create/revoke named tokens** (Filament page under the seller
  panel, "API Anahtarları"): `createToken()` on the user, show the plain token **once**,
  list existing tokens with a revoke action. Store nothing extra — Sanctum's
  `personal_access_tokens` table holds them.
- **Authorization:** `OfferPolicy` — a seller may only create/update **their own org's**
  offers (the policy already enforces this for the panel; reuse it, do not re-implement).
  v1 reuses the existing offer create/update abilities — **no new permission**. Guard
  isolation must hold: an admin/customer token → 403 on these routes.

### P2.2 Endpoints
| Method + path | Body | Action |
|---|---|---|
| `POST /api/v1/seller/offers/sync` | `{ items: [{ gtin, price, stock, list_price? }] }` | `SyncSellerOfferAction` per item |
| `POST /api/v1/seller/offers/stock` | `{ items: [{ gtin, stock }] }` | stock-only per item |
| `POST /api/v1/seller/offers/withdraw` | `{ items: [{ gtin }] }` | withdraw per item |

- Form requests convert **`price`/`list_price` decimal strings → minor units** and reject
  a batch over `config('offer.feed.max_batch')` (default **500**) with `422`.
- **Synchronous**, per-item result. HTTP `200` even with some failed items (a batch is a
  report, not all-or-nothing); `422` only for a malformed request / over-limit.
- Response (API resource):
```json
{ "data": { "processed": 3, "created": 1, "updated": 1, "unchanged": 0, "failed": 1,
  "items": [
    { "gtin": "8690000000001", "status": "created" },
    { "gtin": "8690000000002", "status": "updated" },
    { "gtin": "8690000000009", "status": "failed", "reason": "product_not_in_catalog" }
  ] } }
```
Machine reasons: `product_not_in_catalog`, `offer_not_found`, `invalid_price`,
`invalid_stock`, `list_price_below_price`.

### P2 tests
No token → 401; customer/admin token → 403; seller cannot write another org's offer;
mixed batch (good + bad items) → good persist, bad reported, `200`; over `max_batch` →
422; stock-only + withdraw happy paths. Conform to `docs/005_API_Standards.md`
(envelope, decimal-string money, uuids).

---

## P3 — CSV import (seller panel)

A seller-panel Filament importer mirroring the admin catalogue import (ADR-074), on
Filament's bundled import infra (`league/csv` + `openspout`).
`app/Modules/Offer/Presentation/Filament/Seller/Imports/OfferImporter.php`.

- **Columns:** `gtin`, `fiyat`, `stok`, optional `liste_fiyati`.
- Each row → `SyncSellerOfferAction` (the same brain). A failing row (`product_not_in_catalog`,
  bad price/stock) is recorded in `failed_import_rows` (downloadable report) and skipped.
- Translate `OfferFeedException` → Filament's per-row failure exception so a bad row
  **never throws out of the chunk job** — and set an explicit **`$tries` + `$backoff`** on
  the import job (the ADR-075 retry-storm lesson; do not ship an unbounded-retry job).
- **Idempotent on (seller org, GTIN)** — re-uploading a corrected file updates, never
  duplicates.
- If the `imports` / `failed_import_rows` migrations were only just published for the
  catalogue import, no new publish is needed; confirm they exist.

### P3 tests
Happy path, failure report captures the reason, idempotent re-upload, a row-failure does
not fail the chunk.

---

## P4 — Docs + hardening

- `docs/modules/Offer.md`: a new section documenting the feed (the two doors, the brain,
  the GTIN-reject rule, the drives-not-writes rule), plus its cost. Update the "what
  shipped / open follow-ups" list.
- API surface documented per `docs/005_API_Standards.md`.
- `app/Modules/Offer/README.md` (or the module index) reflects the new controllers/actions.
- Full arch + boundary test pass: `LayeringTest`, `CatalogBoundaryTest` green.
- `make check` green.

---

## Deploy notes

1. `git pull`, `make check`.
2. `php artisan migrate` (only if P3 needs the import tables and they are absent),
   `marketplace:sync-permissions` + `RolePermissionSeeder` **only if** a new ability was
   added (v1 says none — confirm), `optimize:clear`.
3. Queue worker / Horizon running for the CSV import (inert without it).
4. Smoke test: issue a seller token, `POST /api/v1/seller/offers/sync` with a couple of
   real GTINs from the live catalogue → confirm the offers appear on the buy box and
   Inventory availability reflects the stock. Report per-item results + that the buy box
   updated.

---

## Out of scope (v1 — do not build)

Product creation from the feed, multi-variant axes, delta (relative) stock, outbound
webhooks / stock push-back, OAuth2, per-seller rate tuning beyond `throttle:api`.
