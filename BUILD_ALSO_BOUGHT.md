# BUILD — "Bu Ürünü Alanlar Bunları da Aldı" (also-bought) endpoint · ADR-077

**Status:** Ready. Decision: **ADR-077** (`docs/Architecture_Decision_Record.md`),
amendment log #16.

The product page already renders a **"Bu Ürünü Alanlar Bunları da Aldı"** carousel
(`AlsoBought` + `getAlsoBought`), wired to **degrade to empty** — it stays hidden until
this endpoint returns products, then appears on its own. Build the endpoint so it starts
working automatically as sales accumulate. **Frontend needs no change.**

Everything runs in Docker; `make check` must pass.

## The endpoint

`GET /api/v1/products/{product}/also-bought` — public, cached. `{product}` resolves by
**uuid or slug** (same as the other product routes). ADR-009 envelope, `data` = the same
**`ProductCard[]`** shape the browse endpoint returns (id, slug, title, image, category,
brand), up to **12** items. `[]` when there is no co-purchase data.

## Algorithm (computed on read — no stored recommendation table)

1. Resolve `{product}` → its id.
2. Find the **checkout groups** that contain a **paid** order with a line for this product.
   - "Bought together" is the **`checkout_group`** (ADR-052: a basket splits into one order
     per seller under one group), NOT a single seller's order — so a co-purchase can span
     two sellers in one basket.
   - **Only paid orders count** — a cart is not an order; cancelled/expired baskets are not
     purchases. (Use the order status that means "really bought": paid and beyond.)
3. From those same checkout groups, collect the **other** products' lines and count
   co-occurrence (by distinct basket, so buying 3 units in one basket counts once).
4. Exclude the product itself. Rank by frequency desc (tie-break by recency).
5. Keep only **published + sellable** products (≥1 live in-stock offer) — reuse the browse
   "sellable" filter; a suggestion the buyer can't buy is a dead card.
6. Return up to 12 as `ProductCard`, **preserving rank**.

## Boundary (ADR-077)

Recommendations are a **read** concern; **Order** owns the lines. Add a **new Core
`OrderQueryContract` method** returning ranked co-purchased product uuids, e.g.:

```php
/** Product uuids most often bought in the same basket as $productUuid, ranked, paid orders only. */
public function coPurchasedProductUuids(string $productUuid, int $limit): array;
```

**Catalog** implements the endpoint: it calls the contract, then hydrates the uuids into
published+sellable `ProductCard`s **preserving the returned order** (a `whereIn` + an
explicit order-by-position, since SQL `IN` does not preserve order). No module imports
another — Catalog reaches Order only through the Core contract; `LayeringTest` holds. Order
implements the contract against `order_lines` + `orders` (checkout_group, status).

## Caching & scale

- Cache the endpoint (public, ~1h) — it is anonymous and identical for everyone.
- A live co-occurrence query is fine at launch volume. **Follow-up (not v1):** when order
  volume makes it bite, precompute co-occurrence into a periodically-rebuilt table and read
  that instead. Note the cap in a log line if you ever truncate.

## Tests (Feature — touches Order + Catalog)

1. **Co-occurrence:** two paid baskets each containing product A + product B → `A/also-bought`
   returns B (and vice versa), ranked by count.
2. **Checkout-group span:** a basket split across two sellers (two orders, one group) →
   the two products are co-purchases of each other.
3. **Paid only:** a cart / an unpaid / a cancelled basket with A + C → C is NOT returned.
4. **Sellable filter:** a co-bought product that is now unpublished or sold-out → excluded.
5. **Self-exclusion + rank order preserved** (whereIn does not reorder).
6. **Empty:** a product nobody has co-bought → `[]` (200, empty data), and the resolve-by-slug
   path works.
7. **Boundary:** `LayeringTest` + `CatalogBoundaryTest` stay green (no Order import in
   Catalog; the read goes through Core).

## After it lands

1. `make check` green.
2. Deploy: `git pull`, `optimize:clear` (no migration unless you add the precompute table —
   v1 is a live query).
3. The storefront already calls it — a product page with co-purchase history shows the strip
   automatically; one without stays hidden. Verify with two test orders sharing a basket.
