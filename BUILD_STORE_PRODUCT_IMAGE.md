# BUILD — Store page products need an image + slug (small)

**Status:** Ready. Small addition. Fixes two live bugs on `/magaza/{slug}`: product
cards show **no image** and link through a `/urun/{uuid}` **301 redirect** instead of
straight to the product.

## The gap

`GET /api/v1/store/{slug}` composes the store (ADR-036) and Offer contributes what the
shop sells under `extensions.products.items` (ADR-046). Each item today carries:
```json
{ "id","product_id","variant_id","title","brand","price","list_price","currency","in_stock" }
```
It is **missing two fields the storefront needs**:
- **`image`** — the product's primary image URL (or `null`). Without it the card can
  only show a "görsel yok" placeholder.
- **`slug`** — the product's canonical slug (ADR-059). Without it the storefront links
  to `/urun/{uuid}`, which 301s to `/{slug}` — a working but ugly extra hop.

Both are **Catalog** facts the contributor already reaches for `title`/`brand`.

## The change

In the Offer storefront contributor that builds the store's product items (the
`StorefrontContributorContract` impl, ADR-046), add `image` and `slug` to each item,
read through the **Core `CatalogQueryContract`** the contributor already uses for the
title/brand — no new module import, no Offer→Catalog dependency beyond the existing
contract. If `CatalogQueryContract` does not yet expose the primary image / slug for a
variant's product, add those to it (a read-only addition, same shape as the title it
already returns).

- `image`: the product's primary media URL, the same one the product card + listing
  use. `null` when the product has no image.
- `slug`: `products.slug`.

Result item:
```json
{ "id","product_id","variant_id","title","brand",
  "image":"https://…/media/….webp", "slug":"dis-filtre-vantuzu",
  "price","list_price","currency","in_stock" }
```

## Boundary

Store composes; Offer contributes; both read Catalog only through Core contracts —
`LayeringTest` + `CatalogBoundaryTest` stay green. No price/stock moves. This is the
same composition seam the store page already uses; it just carries two more Catalog
fields.

## Tests (Feature)

1. `store/{slug}` items include `image` (nullable) and `slug`.
2. A product with no media returns `image: null`; the storefront then shows the
   placeholder.
3. `slug` matches the product's canonical slug (the one `/{slug}` resolves).
4. Boundary green.

## After it lands

`make check` green; no migration. The storefront `StoreProductCard` is already built
against this: it renders `image` (placeholder until present) and links to `/${slug}`
when present, falling back to `/urun/{uuid}` otherwise — so once the fields ship, store
product cards get their images and clean direct links on their own.
