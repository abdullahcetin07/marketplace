# BUILD — ⚠️ URGENT: the product browse query is ~10s on the live catalogue

**Status:** Ready, urgent. The live storefront is slow and intermittently throwing
"Application error" because of this. Frontend already mitigated (browse-backed rails
degrade + stream so they no longer crash the page); this is the real fix.

## Measured (live, test.raftabul.com, per-request curl timings)

| Endpoint | Time |
|---|---|
| `GET /api/v1/products/{slug}` (single) | **0.39s** |
| `.../offers`, `.../reviews`, `.../questions` (single) | 0.3–0.4s |
| `GET /api/v1/products?per_page=8` (**browse**) | **10.9s** |
| `GET /api/v1/products?category=cilt-bakimi` (**browse**) | **10.0s** |
| `GET /api/v1/products/best-sellers` / `most-reviewed` | 0.28s |

Single-product reads are fine. **Every BROWSE is ~10s**, so the homepage category
strips, the product-page "Benzer/Önerilen" rails, and the `/urunler` listing are all
~10s — and when a browse exceeds the proxy/Next timeout it 500s, which surfaced to
users as "Application error: a server-side exception has occurred".

## Root cause (your own code predicted it)

`PublicProductBrowse::cards()` calls `$this->offers->sellableProductUuids()` — the
"sellable wall" — on every browse. Its docblock already flags this as the scaling wall:

> "a whereIn of a hundred thousand uuids will eventually be the slow part of this
> page. The scaling path is denormalization — a `sellable` flag on the product kept
> current by Offer's events, or the same fact carried on the search index — and it is
> deliberately NOT built now."

It is now. The likely O(n) cost inside `sellableProductUuids()` is computing
`available = on_hand − reserved` **on read** for every offer by summing the Inventory
movement ledger (ADR-048/050) — O(offers × ledger rows). The best-sellers/also-bought
endpoints stay fast because they pass a small `$rankedUuids` set into
`sellableProductUuids($rankedUuids)`, narrowing it; the unbounded browse call does not.

## The fix — denormalize (pick the implementation; confirm the boundary)

The requirement: **browse (`/products`, `?category`, `?brand`, `?q`) drops to
< ~200ms** while keeping the sellable-wall's correctness (an unsold or unpublished
product never reaches a buyer), and **without a Catalog→Offer import** (the Core
contract stays the seam; `LayeringTest` + `CatalogBoundaryTest` green).

Options, most-fundamental first — you own Inventory/Offer, choose what fits:

1. **Materialize the Inventory balance** (the actual root cost). Keep a current
   `on_hand` / `reserved` per (seller org, variant) updated on each movement, instead
   of summing the append-only ledger on every read. `available` becomes a column read,
   not a ledger scan. The ledger stays the source of truth (ADR-050) and can rebuild
   the balance; this is a cache of it. Fixes availability reads platform-wide.

2. **A `sellable` / `in_stock` flag on `products`** (the docblock's suggestion),
   maintained by the Offer stock events Inventory already consumes
   (`OfferCreated/OfferStockChanged/OfferWithdrawn`) + publish/unpublish. Browse then
   filters `->where('sellable', true)` on an **indexed** column — no `sellableProductUuids()`
   call at all. Most targeted for this endpoint.

3. **Carry the sellable fact on the Scout index** — browse already goes through Scout;
   store the flag there and filter on it.

Recommendation: **1 + 2 together** — 1 removes the ledger-sum cost everywhere, 2 makes
browse an indexed boolean filter. But a single well-chosen one may hit the < 200ms bar;
benchmark and decide. Add the index. Backfill the new column/table from current state,
then keep it current with listeners.

## Verify

- Re-run the curl timings: browse (`?per_page=8`, `?category=…`) must be well under a
  second.
- Correctness preserved: a sold-out or unpublished product still never appears in a
  browse (add/keep a test), and re-stocking / publishing makes it appear again after the
  event fires.
- `make check`, `LayeringTest`, `CatalogBoundaryTest` green.
- This likely warrants an ADR (the denormalization the docblock deferred) + a note in
  Storefront.md §1.1 — add it with the change.

## Deploy

`git pull`, `php artisan migrate` (new column/table), run the backfill, `optimize:clear`,
ensure the queue/Horizon is up if the backfill or listeners are queued. Then re-time.
