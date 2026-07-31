# Work order — Storefront Phase A: public buyer read surfaces

**Disposable. `git rm` when done.** For the server-side session. Owner-approved 2026-07-31.
Design: **[docs/modules/Storefront.md](docs/modules/Storefront.md)** (read §1). **ADR-058 is
already recorded owner-side** (ADR record + 001 amendment log) — do not re-author it; if
missing after `git pull`, STOP and report (ADR-018). One commit per group, suite green
(`php artisan test`), human pushes.

This is **Phase A only** — the public API the Next.js storefront (Phase B, separate) will
consume. No frontend here. Catalog and Offer are **not frozen**; both gain a public read.

## What exists already (do NOT rebuild)
Customer **auth** (`/login`,`/register`), **cart** (`CartController`), **address book**
(`CustomerAddressController`), **orders** (`CustomerOrderController`), the per-store page
(`/store/{slug}`), and the per-product buy box (`GET /products/{product}/offers`). The gap
is a **marketplace-wide public product browse/search + product detail**, plus a **batch
price** read.

## Hard rules
Both modules **import nothing** — compose through Core contracts (`CatalogQueryContract`,
`OfferQueryContract`, `InventoryQueryContract`). `LayeringTest` + `CatalogBoundaryTest` stay
green (no price/stock in Catalog). Public surface rules (mirror the Store public surface):
**anonymous**, `throttle:storefront`, **money as decimal strings**, **UUID/slug only**
(never an internal id), **Published/Active/sellable only**, 404 with no existence leak.
`declare(strict_types=1)`; no `dd/dump`; API resources for every response.

## Build

### A — Catalog public product surface (Catalog, not frozen)
- `GET /api/v1/products` — paginated **browse/search** of **published + sellable** products.
  Query params: `q` (text, over the existing search index), `category` (uuid), `brand`
  (uuid), `sort` (`price_asc`|`price_desc`|`newest`), `page`. Each item: `uuid`, `title`,
  `primary_image_url`, `category`, `brand`. **No price on the Catalog item.**
  - **Sellable filter:** return only products with ≥1 active in-stock offer — Catalog asks
    `OfferQueryContract` (add a read like `productUuidsWithActiveOffers(array $uuids): array`
    or `hasActiveOffer(string $productUuid): bool`) and filters. Do **not** import Offer.
  - `sort=price_*` needs the buy-box price; resolve it through the same Offer read (batch),
    sort in the query layer. If price sort is expensive, document the approach.
- `GET /api/v1/products/{uuid}` — **product detail** (published): `title`, `description`,
  gallery image urls, attributes, variants (`uuid` + attribute labels), category path,
  brand. **No price** (the storefront overlays the buy box from the existing offers route).
- Public controllers/resources under Catalog Presentation; register routes in the public
  group in `routes/api.php`. Tests: browse returns only sellable published products; filters
  + sort + search work; detail 404s for a non-published product with no leak.

### B — Offer batch price/availability (Offer, not frozen)
- `POST /api/v1/offers/prices` — body: `{ product_uuids: [...] }`; returns per product its
  **buy-box price** (cheapest active in-stock, decimal string) + `in_stock` bool + currency,
  so a listing renders "from ₺X" in one round trip. Reads Offer + `InventoryQueryContract`.
  Anonymous, throttled, capped list size (e.g. 100). Tests: correct buy-box price for a
  multi-offer product; out-of-stock reflected; unknown uuid omitted (no leak).
- The Core `OfferQueryContract` gains whatever read A's sellable filter needs (keep it a
  plain-array read; no models cross the boundary).

### C — Hardening
- A public-surface test asserting the rules (anonymous, no internal ids in any payload,
  money as decimal strings, non-sellable/non-published excluded).
- `LayeringTest` green (Catalog and Offer still import no module); `CatalogBoundaryTest`
  green (no price/stock leaked into Catalog — the new browse holds no price column, it
  composes).
- Update Catalog.md + Offer.md with a short "public buyer surface" note (what shipped).

## Notes (don't re-litigate — report if you must deviate)
- **Composed read:** Catalog = content, Offer = price/availability. The listing filters to
  **sellable** via `OfferQueryContract`; never add a price column to Catalog.
- **No frontend** in this work order — Phase B (the Next.js `storefront/` app) is separate.
- Search uses the **existing** Catalog index; buyer-facing relevance tuning is a later
  refinement, not this order.

## Finish
`git rm BUILD_STOREFRONT_API.md`, commit. Report the `php artisan test` count and a short
note: `GET /api/v1/products?q=...&category=...` returns sellable cards; `GET /products/{uuid}`
returns detail; `POST /offers/prices` returns buy-box prices. If anything conflicts with the
docs chain, STOP and report.
