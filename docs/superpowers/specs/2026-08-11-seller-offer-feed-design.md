# Seller Offer Feed — Price + Stock Ingress (Design Spec)

**Date:** 2026-08-11
**Status:** Approved design, pending implementation.
**ADR:** ADR-076 (`docs/Architecture_Decision_Record.md`).
**Module:** Offer (extends it; Offer is not frozen). Adds one read-only method to
Core `CatalogQueryContract`.

---

## 0. Summary

Sellers today create offers **one at a time** in the Filament seller panel. A real
store is thousands of SKUs whose **price and stock change daily**, so there is no
workable way to keep them current. This adds a **seller offer feed**: a way to push
price + stock for many products at once.

**Two doors over one brain.** A single upsert action holds all the logic; a
**token-authed REST API** (the priority) and a **CSV import** in the seller panel are
thin adapters over it. The API is for sellers whose systems can integrate; the CSV is
for sellers who only have a spreadsheet.

**It feeds price and stock only — it never creates a product.** A row is matched to an
existing **published** catalogue product by **GTIN**; an unmatched GTIN is reported as
a failed item and nothing is created (the catalogue is admin-built and shared —
ADR-037/074; offers never touch it — ADR-042).

---

## 1. Goal & non-goals

**Goal:** a seller (via API or CSV) supplies `{ GTIN, price, stock }` for many products
and their offers are created or updated accordingly, idempotently.

**Non-goals (v1, explicit):**
- **No product creation.** Unmatched GTIN → failed item. Products arrive via the admin
  catalogue import (ADR-074), separately.
- **No multi-variant.** v1 products carry one default variant (ADR-074); a feed row
  addresses that variant. Colour/size axes are a later phase.
- **No delta stock.** Stock is an absolute set (`stock = 12`), not `+3/−1`.
- **No outbound webhooks / no order or stock push back to the seller.** Read APIs for
  that already exist and are out of scope here.
- **No OAuth2.** Per-seller Sanctum bearer tokens (see §7).

---

## 2. Architecture — one action, two doors

```
                    ┌─────────────────────────────┐
  REST API  ──────► │                             │
  (priority)        │   SyncSellerOfferAction     │ ──► CreateOfferAction
                    │   (upsert by seller+variant)│ ──► UpdateOfferPriceAction
  CSV import ──────► │                             │ ──► UpdateOfferStockAction
  (seller panel)    └─────────────────────────────┘ ──► WithdrawOfferAction
                                  │
                                  ▼
                    Core CatalogQueryContract
                    (GTIN → published variant uuid)
```

Both doors call **one** application action. Neither door contains feed logic; they only
translate their input (a JSON item / a CSV row) into the action's input and render its
result (a JSON response / a failure report).

**The action DRIVES the existing offer actions — it does not write the Offer model.**
This is the load-bearing rule (mirrors ADR-074). `CreateOfferAction` /
`UpdateOfferStockAction` emit `OfferCreated` / `OfferStockChanged`, which **Inventory
mirrors on-hand from** (ADR-048) and the **search index** consumes. Writing the model
directly would create offers that are invisible to availability and search — right in
the table, wrong everywhere else.

---

## 3. `SyncSellerOfferAction` (the brain)

**Input (one item):** `sellingOrg` (from the authenticated actor, §7), `gtin`,
`price` (minor units, resolved from the decimal string at the edge), `stock`,
optional `listPrice`.

**Steps:**
1. Resolve `gtin` → published variant uuid via `CatalogQueryContract` (§4). Null →
   throw a domain failure `OfferFeedException::productNotInCatalog($gtin)`.
2. Look up this seller org's existing offer for that variant (`OfferRepositoryContract`).
3. **Upsert:**
   - **Not found →** `CreateOfferAction` with price + stock (+ listPrice).
   - **Found →** `UpdateOfferPriceAction` (if price/listPrice changed) and/or
     `UpdateOfferStockAction` (if stock changed). No-op fields are skipped so a
     re-send that changes nothing emits nothing.
4. Return a per-item outcome: `created | updated | unchanged | failed(reason)`.

It is an **Action** (one transaction, verb+noun, `handle()`), the batch loop lives in
the adapter (controller / importer), one action invocation per item — so one item's
failure never rolls back its neighbours.

**Stock-only path:** the `offers/stock` endpoint (§5) calls a narrower variant that runs
steps 1–2 + `UpdateOfferStockAction`. It **requires an existing offer** — there is no
price to create one with — so a variant this seller has no offer for is
`failed(offer_not_found)`, meaning "run `sync` first". **Withdraw** calls
`WithdrawOfferAction`.

---

## 4. Core contract addition (read-only)

`CatalogQueryContract` gains **one** method:

```php
/** The published product's default variant uuid for this GTIN, or null. */
public function publishedVariantUuidForGtin(string $gtin): ?string;
```

Catalog implements it (GTIN is already `UNIQUE` on `products`); it returns null when the
GTIN is unknown OR the product is not published. Offer reads it through the Core
interface only — **Catalog stays unaware of Offer**, `LayeringTest` and
`CatalogBoundaryTest` stay green (no price/stock touches Catalog).

---

## 5. REST API (Door 1 — the priority)

Base: `/api/v1/seller/offers`, guard `auth:sanctum` scoped to a seller actor (§7),
`throttle:api`. All idempotent. Money is a **decimal string** on the wire ("129.90"),
converted to minor units at the request boundary; never a float.

### `POST /api/v1/seller/offers/sync`
Full price + stock upsert.
```json
{ "items": [ { "gtin": "8690000000001", "price": "129.90", "stock": 12, "list_price": "159.90" } ] }
```
- `list_price` optional. Max **N items per call** (config `offer.feed.max_batch`,
  default 500) — over the limit → `422`, so a huge POST never ties up a worker.
- **Synchronous**, per-item result:
```json
{ "data": { "processed": 3, "created": 1, "updated": 1, "failed": 1,
  "items": [
    { "gtin": "8690000000001", "status": "created" },
    { "gtin": "8690000000002", "status": "updated" },
    { "gtin": "8690000000009", "status": "failed", "reason": "product_not_in_catalog" }
  ] } }
```
HTTP `200` even with some failed items (the batch is a report, not all-or-nothing);
`422` only for a malformed request (bad shape, over the batch limit).

### `POST /api/v1/seller/offers/stock`
Stock-only fast path — the common daily call.
```json
{ "items": [ { "gtin": "8690000000001", "stock": 7 } ] }
```
Same response shape.

### `POST /api/v1/seller/offers/withdraw`
Take offers off sale.
```json
{ "items": [ { "gtin": "8690000000001" } ] }
```

---

## 6. CSV import (Door 2 — seller panel)

A seller-panel importer mirroring the admin catalogue import (ADR-074): Filament's own
import infra (`league/csv` + `openspout`, already bundled), **queued + chunked**, with a
downloadable per-row failure report.

- **Columns:** `gtin`, `fiyat`, `stok`, optional `liste_fiyati`.
- Each row → `SyncSellerOfferAction` (same brain). A row that fails
  (`product_not_in_catalog`, bad price/stock) is recorded and skipped; it never fails the
  others.
- **Idempotent on (seller org, GTIN)** — re-uploading a corrected file updates rather
  than duplicates (there is one offer per seller+variant).
- **Queue worker required** — inert without `queue:work`/Horizon (same caveat as the
  catalogue import). The `$tries`/`$backoff` retry-cap lesson from ADR-075 applies: a
  rejected row fails at the row level and never storms the job.

---

## 7. Auth & authorization

- **A dedicated `sanctum_seller` guard — NOT the existing `sanctum` guard.** The existing
  `sanctum` guard is bound to the **customers** provider
  (`auth.guards.sanctum.provider = customers`), so a seller's token fails
  `Sanctum::hasValidProvider()` and 401s — reusing it was the design's one wrong
  assumption (caught at build, ADR-018). Instead add a `sanctum_seller` guard (driver
  `sanctum`, provider `sellers` → `App\Models\Seller`); the feed routes use
  `auth:sanctum_seller`. This leaves the customer `sanctum` guard untouched (no
  platform-wide relaxation) and **preserves guard isolation by construction**: only a
  `Seller` tokenable resolves on it, so an admin/customer token cannot authenticate here.
- **Actor resolution, second layer.** `current_actor()` / `BaseRequest::actor()` consult
  only the named admin/seller/customer guards today, which are empty on a token request —
  so even past auth, actor resolution would 403. They must also consult `sanctum_seller`
  (or the feed controllers name the guard explicitly) so the token's `Seller` resolves as
  the seller actor. Spatie permissions still key off the `Seller` model's `seller`
  `guard_name`, so policies/abilities are unchanged.
- A seller generates/revokes named tokens in the seller panel (a new "API Anahtarları"
  page); the tokenable is their `Seller` user.
- **Acting org** = the seller panel's existing current-org resolution for that user; the
  feed writes offers for **that org only**. A multi-org user's token is bound to one org
  (v1: single acting org, consistent with the panel).
- **Authorization:** `OfferPolicy` — a seller may only create/update **their own org's**
  offers (the policy already owns this for the panel; the API reuses it). v1 **reuses the
  existing offer create/update abilities** the seller panel already checks — no new
  permission; API access is gated by those abilities plus token possession. (A separate
  `offer.sync` ability, to revoke bulk-API access without removing panel access, is a
  deliberate later option, not v1.)
- **Guard isolation** holds: an admin/customer token cannot reach these routes.
- **No financial data** passes through these calls — price is a catalogue amount, not a
  card or bank number.

---

## 8. Semantics (the rules)

| Rule | Value |
|---|---|
| Match key | `gtin` → **published** product's default variant (§4) |
| Unmatched / unpublished | item `failed(product_not_in_catalog)`, nothing created |
| Upsert key | `(seller org, variant)` — one offer per pair (ADR-042) |
| Stock | **absolute** integer ≥ 0; overwrites |
| Price | decimal string on wire → minor units internally; > 0 |
| `list_price` | optional; if present must be ≥ price (else item failed) |
| Idempotency | re-sending identical values → `unchanged`, emits no event |
| Currency | platform default (TRY) v1; no per-item currency |

Validation failures (missing gtin, non-numeric price, negative stock, list_price <
price) are **per-item** `failed` outcomes with a machine reason, not a whole-request
`422`.

---

## 9. Module boundary & layering

- Lives entirely in **Offer** + the one Core contract method. Offer **imports no
  module** (unchanged); it reads Catalog only through `CatalogQueryContract`.
- `LayeringTest` (no cross-module imports) and `CatalogBoundaryTest` (no price/stock in
  Catalog) must stay green — the GTIN lookup returns a uuid string, carrying no offer
  concept into Catalog.
- Feed writes flow through the existing offer actions, so `OfferCreated /
  OfferStockChanged / OfferPriceChanged / OfferWithdrawn` fire as today → Inventory
  mirror + search index stay correct with **no new wiring**.

---

## 10. Errors & reporting

- **API:** per-item `status` + machine `reason` in the response (§5). Reasons:
  `product_not_in_catalog`, `invalid_price`, `invalid_stock`, `list_price_below_price`.
- **CSV:** Filament `failed_import_rows` → downloadable report, one row per failure with
  the reason (localized).
- Domain failures are expected, non-reportable (`BaseException::$reportable = false`) —
  a bad row is not an incident.

---

## 11. Testing

Feature tests (the feed touches the DB):
1. **Create path:** unknown seller+variant + valid item → offer created, `OfferCreated`
   emitted, Inventory on-hand mirrors.
2. **Update path:** existing offer → price and stock updated; unchanged fields emit
   nothing.
3. **Reject:** GTIN not in catalogue / product not published → `failed`, no offer, no
   event.
4. **Idempotency:** same item twice → second is `unchanged`.
5. **Batch isolation:** one bad item among good ones → good ones persist, bad one
   reported.
6. **Auth:** no token → 401; customer/admin token → 403; seller cannot write another
   org's offer (policy).
7. **Batch limit:** over `max_batch` → 422.
8. **Stock-only + withdraw** endpoints.
9. **CSV:** happy path + failure report + idempotent re-upload.

---

## 12. Phasing (for the work order)

- **P1 — Brain + contract.** `SyncSellerOfferAction` (+ stock-only, withdraw variants),
  `OfferFeedException`, the Core `publishedVariantUuidForGtin` method + Catalog impl.
  Unit/feature tests for the action.
- **P2 — REST API (priority).** Controllers, form requests (decimal→minor, batch limit),
  API resource, routes under `auth:sanctum` seller scope, `OfferPolicy` + `offer.sync`
  permission, seller-panel token management page. API tests.
- **P3 — CSV import.** Seller-panel Filament importer over the same action, queued,
  failure report, retry-cap (ADR-075 lesson). Import tests.
- **P4 — Docs + hardening.** `docs/modules/Offer.md` §addition, API docs
  (`docs/005_API_Standards.md` conformance), arch/boundary test pass, `make check`.

---

## 13. Cost (stated plainly)

A new **public write surface** is a new attack surface and a versioning obligation
(`/api/v1`), plus a token lifecycle to manage (issue/revoke). The synchronous bulk
endpoint must bound its batch or a large POST occupies a worker — hence the `max_batch`
cap and the CSV/queue path for the thousands. We accept it because per-form entry does
not scale to a live multi-thousand-SKU store, and the shared action guarantees the API
and CSV can never diverge.
