# Customer Storefront Specification

**Status: APPROVED 2026-07-31 — Phase A building.** The owner approved the design; the §4
rulings are ratified. **ADR-058 is recorded** in
[docs/Architecture_Decision_Record.md](../Architecture_Decision_Record.md) with its mirror
in the amendment log at the end of [docs/001_Architecture.md](../001_Architecture.md).
States each decision **and its cost**, per project culture. Phase A build order:
[BUILD_STOREFRONT_API.md](../../BUILD_STOREFRONT_API.md).

The storefront is the **buyer-facing web application** — the shopper's marketplace (home,
search, product pages, cart, checkout, account/orders). Every backend module so far
(Catalog, Offer, Inventory, Order) was built **API-first for exactly this consumer**. This
document covers **both** halves: the **public backend read surfaces** the storefront needs
(built first), and the **Next.js application** that consumes them.

---

# 0. Scope and the decisions

## 0.1 ADR-058 — A separate Next.js app on the same origin; the buyer read is composed

**The storefront is a separate Next.js application** (owner decision, 2026-07-31), not a
Blade/Livewire surface in the monolith — the original architecture intent. It lives in a
**`storefront/` folder in this repo** (monorepo: one git flow for the server-side build
loop) and is served on the **same origin** as the API — the storefront at the root, the
Laravel API under `/api` (nginx routes `/api` → PHP-FPM, everything else → the Next.js
process). Same origin means **Sanctum SPA cookie auth "just works"** — no CORS, no token
storage, the customer's session is an httpOnly cookie.

**The buyer read is composed, not owned by one module** — the same principle as the
storefront composition (ADR-036):

- **Catalog owns product CONTENT** — public product **detail** and **browse/search** over
  its existing index. Title, gallery, attributes, variants, category, brand. **No price, no
  availability** (ADR-037 holds).
- **Offer owns price + sellability** — the buy box (already `/products/{id}/offers`) and a
  **batch price/availability** read for a set of products, so a listing shows "from ₺X".
- **Only sellable products are listed** — the marketplace home/search shows products with
  **≥1 active, in-stock offer**; Catalog's browse filters by asking `OfferQueryContract`.
  The product *detail* page is reachable for any published product, but shows "satışta yok"
  when no offer exists.

**Cost.** A separate app is a second runtime (a Node process under a systemd unit +
`next build` on deploy) and a second thing to operate on a bare-metal box that does not yet
run even a queue worker; and every listing item is a Catalog-content + Offer-price
composition, two reads instead of one table. We accept both because the owner chose the
decoupled frontend, and because letting Catalog carry price (the one-read alternative)
would breach the boundary the whole platform is built on (ADR-037).

## 0.2 What the storefront is NOT

Not the admin or seller panels (Filament, shipped). Not payment (checkout stops at
**awaiting payment**, ADR-054/057 — the storefront shows "sipariş alındı, ödeme yakında").
Not a CMS, not seller onboarding (that is the seller panel). It reads the public surfaces
and drives the customer cart/checkout/order APIs that already exist.

## 0.3 Scope of THIS effort (phased)

**Phase A — backend public read surfaces (built first, server-side):** Catalog public
product **detail** + **browse/search** (published, sellable-filtered); Offer **batch
price/availability**; wire the "sellable" filter through `OfferQueryContract`. Anonymous,
throttled, allow-listed (UUID/slug only, no internal ids), only Published/Active/sellable.

**Phase B — the Next.js app (`storefront/`):** home + search/listing, product page (gallery
+ variants + buy box + sellers), cart, address book, checkout → order placed, account +
my-orders, auth (login/register). Consumes the public surfaces + the existing customer APIs.

**Phase C — deploy:** the `storefront/` build, a systemd Node unit, nginx same-origin
routing. Documented, owner runs it.

**Out of scope now:** payment UI, guest checkout, wishlists/reviews/ratings, i18n beyond
tr+en, PWA/native.

---

# 1. Phase A — public backend read surfaces

## 1.1 Catalog — public product surface (Catalog not frozen)
- `GET /api/v1/products` — paginated **browse/search** of **published + sellable** products.
  Query: `q` (text, over the existing search index), `category`, `brand`, `sort`
  (price_asc/desc, newest), page. Each item: uuid, title, primary image, category, brand.
  **Sellable filter:** Catalog asks `OfferQueryContract` which products have an active
  in-stock offer and returns only those. **No price on the Catalog item** — the storefront
  overlays it from Offer (§1.2).
- `GET /api/v1/products/{uuid}` — **product detail** (published): title, description,
  gallery, attributes, variants (uuid + attribute labels), category path, brand,
  **`gtin`** (nullable). No price.
  - **The GTIN is on the detail surface only** (owner-approved, 2026-08-01). It is
    printed on the box the shopper is holding, so withholding it protects nothing and
    showing it lets them confirm the item — the design's "Barkod" row. The **listing**
    still excludes it: one product's barcode is a fact about that product, and every
    product's barcode, paginated, is a catalogue export keyed for matching.
  - **`attributes` was already returned** and needs no code. It is empty unless the
    **category defines** attributes and the **seller filled them in** at authoring — an
    empty spec table is a content gap, not a bug.
- Extends `CatalogQueryContract` if a read it lacks is needed (e.g. a card projection).

### Flat SEO URLs (ADR-059, added 2026-08-03)
The storefront addresses everything at the ROOT — `/bioderma`, `/cilt-bakimi`,
`/avene-...-krem` — with no type prefix, so a single catch-all route serves three page
types and needs to be told which:

```
GET /api/v1/resolve/{slug}   → { type: product|category|brand, id, slug, canonical_slug }
                               404 for unknown, unpublished, or deactivated
GET /api/v1/categories       → the active tree; each node { id, name, slug, parent_id,
                                 product_count, children[] }
GET /api/v1/categories/{slug|uuid}
                             → { id, name, slug, product_count,
                                 path[] (breadcrumb, root first, INCLUDING itself),
                                 children[] }
GET /api/v1/brands           → [{ id, name, slug, logo, product_count }] — sellable only
GET /api/v1/brands/{slug|uuid}
                             → one brand; renders even with nothing for sale
```

- **`canonical_slug` is the 301 signal.** Equal to `slug` on an ordinary hit; different
  means the visitor followed a retired alias and should be redirected there.
- **`product_count` is of SELLABLE products** and rolls up the tree, so a menu promising
  "48" and the listing it opens cannot disagree.
- **Product detail and the listing filters now take a slug OR a uuid**
  (`/products/{slug}`, `?category={slug}`, `?brand={slug}`), and every breadcrumb node
  and the brand carry their `slug` — a crumb is a link.
- **A reserved-word list guards the storefront's own pages.** A new static route must be
  added to `config('catalog.slugs.reserved')` on the backend **before** the frontend ships
  it, or a product may already occupy that address — silently, since the static route wins
  and the product simply becomes unreachable.

## 1.2 Offer — price & availability for the buyer (Offer not frozen)
- `GET /api/v1/products/{uuid}/offers` — the buy box (**exists**): featured offer + seller
  list + per-offer availability (from Inventory).
  - **The seller is named** (added 2026-08-01): each offer's `store_id` became
    `store: {id, name, city}`, read through a new `StoreQueryContract::publicProfilesFor()`
    (frozen Store's second granted addition — see [Store.md](Store.md)). Offer holds
    store uuids and may not import Store, so a buy box could only say
    "Satıcı: a1086566-10aa-…". **`city` is null on every store today** — no seller form
    writes a contact address yet. **Seller rating is out of scope**: it needs a Review
    system, and a faked one is worse than none.
- `POST /api/v1/offers/prices` (batch) — given a list of product uuids, return each one's
  **buy-box price** (cheapest active in-stock) + in/out of stock, so a listing renders
  "from ₺X" in one round trip. Reads its own data + `InventoryQueryContract`.
  - **Also `seller_count` and `list_price`** (added 2026-08-01) for "N satıcı" and the
    struck-through price. `seller_count` counts **distinct merchants**, not offers — an
    offer is per variant, so one seller listing three sizes is one choice. `list_price`
    is the **winner's** (a shared catalogue has no product-level "was" price) and is
    null when they declared none. **No discount %**: the client has both numbers.

## 1.3 Shared rules
Anonymous, `throttle:storefront`, money as **decimal strings**, UUID/slug only (never an
internal id), Published/Active/sellable only, 404 with no existence leak. Both modules stay
**import-free** (compose through Core contracts). The per-store page (`/store/{slug}`,
ADR-036) already composes Offer's contributor — unchanged.

## 1.4 What already exists (no build)
Customer **auth** (`/login`, `/register`), **cart** (`CartController`), **address book**
(`CustomerAddressController`), **orders** (`CustomerOrderController`), per-store page,
per-product buy box. The storefront drives these directly.

---

# 2. Phase B — the Next.js application (`storefront/`)

## 2.1 Stack & structure
Next.js (App Router), TypeScript, server components for SEO on listing/product pages, a
typed API client hitting `/api/v1`. Tailwind for styling. Auth via Sanctum SPA cookies
(the client calls `/sanctum/csrf-cookie` then `/api/v1/.../login`; the session cookie rides
every request). State: server components + a light cart/session context.

## 2.2 Pages (v1)
- **Home** — hero + campaign banners, featured/newest sellable products, categories.
- **Search / listing** — `q` + category/brand filters + sort; product cards ("from ₺X").
- **Product** — gallery, title, attributes, variant picker, **buy box** (featured offer +
  "other sellers"), add-to-cart. "satışta yok" when no offer.
- **Cart** — multi-seller grouping preview, quantities, totals.
- **Checkout** — pick/create shipping + billing address (address book), review, place →
  **order placed, awaiting payment** (payment UI later).
- **Account** — profile, address book CRUD, **my orders** (grouped by checkout, per-seller).
- **Auth** — login / register (Customer).
- **Store page** — `/magaza/{slug}` composed store storefront (already an API).

## 2.3 Design — LOCKED (owner-approved 2026-07-31)
The **modern marketplace** direction (Trendyol-style), approved after comparing three
mockups. Not the editorial/apothecary variant.
- **Palette:** clean white surfaces on a light grey ground (`#F4F5F7`), vivid **orange**
  brand/CTA (`#FA5A00`, deep `#E24E00`, tint `#FFF0E7`); semantic green (free shipping /
  in-stock `#129D5E`), red discount badges (`#E11D48`), violet accent for promo tiles.
  Full dark theme via tokens.
- **Type:** **Manrope** (400–800) — modern geometric sans, Turkish (latin-ext) covered.
- **Components:** rounded cards (16px), category **circle** icons, orange campaign hero +
  promo tiles, **coupon strip** (dashed), dense product grid (heart, discount %, rating
  pill, seller count, free-shipping/fast badges, struck-through + big orange price,
  "Sepete Ekle"), product page with the multi-seller **buy box** (featured + other
  sellers). **Mobile:** sticky search header, scrollable category chips, 2-col grid,
  **bottom tab bar**, product page with a **sticky bottom "Sepete Ekle / Hemen Al" bar**.
- SVG icons only (no emoji), visible focus rings, `prefers-reduced-motion`, 4.5:1 contrast.
- Reference mockups (built with the ui-ux-pro-max + frontend-design skills): desktop +
  mobile, using the owner's real product imagery. tr-first.

---

# 3. Phase C — deployment (bare-metal, same origin)
- `storefront/`: `next build` → run with `next start` under a **systemd** unit (the same
  pattern the queue worker needs — a good moment to set both up).
- **nginx**: same server block — `location /api { fastcgi → PHP-FPM }`,
  `location / { proxy_pass → 127.0.0.1:3000 (Next) }`. One origin, one cookie domain.
- Env: the storefront reads the API at a relative `/api/v1` (same origin → no base URL
  juggling). `APP_URL` / Sanctum `stateful` domains include the host.
- Node on the box (LTS). Documented run-book; owner executes.

---

# 4. Open rulings to confirm at approval
1. **Separate Next.js app, monorepo `storefront/`, same origin, Sanctum SPA cookie**
   (owner-confirmed).
2. **Buyer read composed:** Catalog = content (detail + browse/search), Offer = price/
   availability; **only sellable products listed** (confirm the sellable filter belongs in
   Catalog's browse via `OfferQueryContract`).
3. **Checkout stops at awaiting-payment** with a clear "ödeme yakında" state (no payment UI).
4. **Design** reuses the Enerjik+Kurumsal direction + real product images (confirm).

## Ratification checklist
- [x] Record ADR-058 in the ADR record + amendment log (2026-07-31).
- [x] Confirm the §4 rulings (owner-approved: separate Next.js, same origin, composed read).
- [ ] Build Phase A (backend public surfaces) first — [BUILD_STOREFRONT_API.md](../../BUILD_STOREFRONT_API.md).
- [ ] Then Phase B (Next.js app) and Phase C (deploy).
