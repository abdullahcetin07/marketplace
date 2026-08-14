# BUILD — Listing filters: price range + brand facet · ADR-080

**Status:** Ready. Decision: **ADR-080** (`docs/Architecture_Decision_Record.md`),
amendment log #18. Frontend is built against this contract and degrades to no-filters
until it ships.

The storefront listing pages (category, brand, search) already sort. This adds a
**price-range filter** and the **brand facet DATA** the UI needs. `category + brand`
filtering already works (verified live); the gaps are the price filter and the facet
lists.

Everything runs in Docker; `make check` must pass.

## The browse endpoint — two request params + one meta block

`GET /api/v1/products` (the buyer browse, `PublicProductBrowse`) gains:

### Request
- `price_min`, `price_max` — **decimal strings** (TL, e.g. `49.90`), converted to minor
  units at the boundary, applied against the buy-box (Offer) price — the same price the
  `price_asc`/`price_desc` sort already orders by. Either may be omitted.
- (`category`, `brand`, `q`, `sort`, `page`, `per_page` unchanged. `category + brand`
  together already narrows — keep it.)

### Response `meta.facets`
Add a `facets` object to the existing `meta`:
```json
"facets": {
  "brands": [ { "slug": "maruderm", "name": "Maruderm", "count": 20 }, … ],
  "price":  { "min": "49.90", "max": "1299.00" }
}
```
- `brands`: brands present in the current query, **each with a count**, so the UI offers
  only brands that return results. Ordered by count desc (cap ~40).
- `price`: the min/max buy-box price of the current query, decimal strings — the range
  control's bounds.

## Faceting rule

Facets are computed over the query **minus the applied `brand` and `price_min/max`** — so
a shopper who has picked a brand still sees the others to switch to, and the price bounds
don't collapse to the filtered subset. **`category` and `q` DO scope the facets.** (Standard
faceting: a facet doesn't hide its own siblings.)

## Boundary

Price and the price facet live in **Offer**. Read them through the **same Core contract the
sellable-wall already uses** (`OfferQueryContract`) — a price-range predicate on sellable
products and a min/max price for a uuid set. Catalog imports no Offer; `LayeringTest` and
`CatalogBoundaryTest` stay green. The brand facet is Catalog's own (group the sellable
products in scope by brand). The **`is_sellable`** flag (ADR-079) keeps all of this an
indexed read — do NOT reintroduce the per-offer walk.

## Performance

These are the same hot listing reads ADR-079 just made fast. The price predicate and the
brand grouping must stay indexed reads (no N+1, no per-offer hydration). Re-time
`/products?category=…&price_min=…&price_max=…` and `…` with facets — must stay well under a
second. Cache as the browse already is.

## Tests (Feature)

1. `price_min`/`price_max` narrow the result set (and the total) correctly; out-of-range
   products drop; boundary inclusive.
2. `meta.facets.brands` lists exactly the brands with sellable products in the category,
   with correct counts, and does NOT collapse when a brand is applied.
3. `meta.facets.price` is the min/max buy-box price of the (category/q) scope.
4. `category + brand + price` compose.
5. Boundary: `LayeringTest` + `CatalogBoundaryTest` green.
6. Timing note: the filtered/faceted browse stays sub-second (ADR-079 path preserved).

## After it lands

`make check` green; deploy (`git pull`, `optimize:clear`, no migration — read-only). The
storefront already sends `price_min/price_max` and reads `meta.facets`, so the filter UI
lights up on its own: price control works, and the brand facet list appears. Verify on a
big category (e.g. `/cilt-bakimi`): pick a price range and a brand, confirm the grid + total
narrow and the URL carries the filters.
