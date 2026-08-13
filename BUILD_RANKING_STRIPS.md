# BUILD — "Çok Satanlar" + "En Çok Değerlendirilenler" endpoints · ADR-078

**Status:** Ready. Decision: **ADR-078** (`docs/Architecture_Decision_Record.md`),
amendment log #17. Same compute-on-read pattern as ADR-077 (`BUILD_ALSO_BOUGHT.md`) — read
it if the also-bought endpoint isn't built yet; these three share the shape and boundary.

The homepage already renders both strips (`ProductRail` + `getBestSellers` /
`getMostReviewed`), wired to **degrade to empty** — each stays hidden until its endpoint
returns products, then appears on its own. Build the endpoints; **frontend needs no change.**

Everything runs in Docker; `make check` must pass.

## Two endpoints

Both public, cached, ADR-009 envelope, `data` = the browse **`ProductCard[]`** shape (id,
slug, title, image, category, brand), up to **12**, **published + sellable** only (≥1 live
in-stock offer — reuse the browse filter), `[]` when there is no data yet.

### `GET /api/v1/products/best-sellers`
Ranked by **units sold across paid orders**.
- Sum `order_lines.quantity` (or line count) grouped by product, over orders that are
  **paid or beyond** — a cart is not a sale; cancelled/expired baskets are not sales.
- Read the ranked uuids through a **new Core `OrderQueryContract` method** (the sibling of
  ADR-077's co-purchase method):
  ```php
  /** Product uuids by units sold across paid orders, ranked desc. */
  public function bestSellingProductUuids(int $limit): array;
  ```
  Order implements it against `order_lines` + `orders` (status).

### `GET /api/v1/products/most-reviewed`
Ranked by **published (approved) review count**.
- Count approved/visible reviews grouped by product, ranked desc.
- Read the ranked uuids through a **new Core Reviews query-contract method**:
  ```php
  /** Product uuids by published review count, ranked desc. */
  public function mostReviewedProductUuids(int $limit): array;
  ```
  Reviews implements it against its reviews table (published + not-hidden, per ADR-068/069).

## Hydration & boundary (ADR-078)

**Catalog** owns both endpoints. For each: call the Core method for ranked uuids, then
hydrate them into published+sellable `ProductCard`s **preserving rank** (`whereIn` + an
explicit order-by-position — SQL `IN` does not preserve order). Catalog reaches Order and
Reviews **only through Core contracts**; no module imports another. `LayeringTest` and
`CatalogBoundaryTest` stay green.

## Caching & scale

- Cache both (public, ~1h) — anonymous, identical for everyone.
- Live aggregation is fine at launch volume. **Follow-up (not v1):** precompute the two
  rankings into a periodically-rebuilt table when volume bites — the same note as ADR-077.

## Tests (Feature)

**best-sellers:** paid orders with product A×3 + B×1 → A ranks above B; an unpaid/cancelled
basket does not count; a sold-out/unpublished best-seller is excluded; rank order preserved;
no sales → `[]`.
**most-reviewed:** product with 5 published reviews ranks above one with 2; a
pending/hidden review does not count; no reviews → `[]`.
**boundary:** `LayeringTest` + `CatalogBoundaryTest` green (Order/Reviews read via Core only).

## After it lands

1. `make check` green.
2. Deploy: `git pull`, `optimize:clear` (no migration in v1 — live queries).
3. The storefront already calls both — the strips appear on the homepage once there are
   sales / reviews; until then they stay hidden. Verify with a couple of paid test orders
   and an approved review.
