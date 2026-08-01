# Work order — enrich the public buyer API so the storefront fills out

**Disposable. `git rm` when done.** For the server-side session (backend). Owner-approved.
The Next.js storefront (`storefront/`, frontend = desktop session — **do not edit `storefront/`
here**) is built and live, but the product page and listing cards look sparse because the
public API returns less than the design shows. This adds the missing **read-surface data**.
No new architecture — enrich existing ADR-058 public responses. Catalog + Offer are not
frozen. `LayeringTest` + `CatalogBoundaryTest` stay green; money stays **decimal strings**.

## What the storefront needs (verified against the live API)

### A — Catalog: product detail exposes `gtin` (Barkod)
`GET /api/v1/products/{id}` currently returns
`{id, slug, title, description, images, category, brand, attributes, variants}` — **no
`gtin`**. The data EXISTS: `Product.gtin` is stored and **entered by the seller at
authoring** (verified — the seller `ProductResource` captures it). It is **deliberately
withheld** from the public surface today: `PublicProductCardResource`'s docblock lists "the
GTIN" among the excluded fields. **Re-expose it on the DETAIL surface only** — add
`gtin` (string, nullable) to `PublicProductResource`. A barcode is printed on the physical
product, so it is public-safe; this is not leaking anything private (unlike the internal id,
moderation state or proposing company, which stay hidden). The card surface can keep
excluding it.
- **Attributes are already returned** (`attributes: [{name,value}]`) — no code change. They
  are empty for a product authored without them; "Cilt Tipi / Hacim / Kullanım" appear only
  when the **category defines those attributes** and the **seller entered them at authoring**.
  That is content, not a bug — note it back so the owner knows to fill them (or the starter
  taxonomy seeds a schema).

### B — Offer: batch prices add `seller_count` + `list_price`
`POST /api/v1/offers/prices` returns per product `{from_price, currency, in_stock}`. The
listing cards want two more, so they can show "N satıcı" and a struck-through price:
- **`seller_count`** — number of Active in-stock offers for the product.
- **`list_price`** — the featured offer's `list_price` (decimal string, nullable) for the
  strike-through. Do **not** compute a discount %; the storefront shows the struck price.
Extend the `BuyBoxPrices` payload with these two fields (the frontend type will follow).

### C — Offer: the offers response adds the seller's city
`GET /api/v1/products/{id}/offers` — each `store` is `{id, name}`. **Add `city`** (the
seller organization's / store's city, if held) so the buy box can show "Satıcı · İstanbul".
- **Seller rating is NOT available** and is out of scope here — it needs a review system
  (a future **Review** feature, its own sprint). Do not fake a rating.

### D — Ops: product images (the "görsel yok" everywhere)
Most products render "görsel yok" because Spatie image **conversions were never generated**
(the queue is not being processed). Fix the data + stand up the worker:
```
php artisan queue:work redis --stop-when-empty
php artisan media-library:regenerate
```
Then create a **persistent queue worker** (systemd, like `raftabul-storefront`) so new
uploads convert and notifications/emails stop piling up. Confirm the browse + detail API
return a **resolvable** primary image URL (the public original if a conversion is missing,
not a dead conversion path).

### E — (Optional, small) prune the Faker demo products
The listing is polluted with seeded Faker products ("Eaque Adipisci Nemo", ₺120, no image).
If the owner wants a clean demo, prune the seeded/test published products (keep the real
ones the owner created). This is data, do it only if asked.

## Deferred — NOT in this work order (note only)
- **Ratings / reviews** on cards + buy box → a new **Review** feature/module, its own spec.
- **Free-shipping / fast-delivery badges** → the **Shipping** module (later sprint).

## Rules
Read surfaces only; anonymous, throttled, decimal-string money, UUID/slug only, sellable/
published only. No cross-module imports — if Catalog needs the seller count it asks
`OfferQueryContract`, but B/C put the counts on **Offer's** own endpoints where they belong.
Add/extend feature tests for the new fields. Suite green, PHPStan clean.

## Finish
`git rm BUILD_STOREFRONT_DATA.md`, commit. Report which fields were added and a note on
whether real products actually carry attributes (so the owner knows if it is an authoring
gap). Frontend wiring (rendering the new fields) is the desktop session's — just ship the
API and say which fields are now present.
