# Customer Storefront Specification

**Status: DRAFT — pending owner approval and ADR ratification.** Proposes **ADR-058**.
Once approved it is recorded in
[docs/Architecture_Decision_Record.md](../Architecture_Decision_Record.md) with a mirror in
the amendment log at the end of [docs/001_Architecture.md](../001_Architecture.md). States
each decision **and its cost**, per project culture.

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
  gallery, attributes, variants (uuid + attribute labels), category path, brand. No price.
- Extends `CatalogQueryContract` if a read it lacks is needed (e.g. a card projection).

## 1.2 Offer — price & availability for the buyer (Offer not frozen)
- `GET /api/v1/products/{uuid}/offers` — the buy box (**exists**): featured offer + seller
  list + per-offer availability (from Inventory).
- `POST /api/v1/offers/prices` (batch) — given a list of product uuids, return each one's
  **buy-box price** (cheapest active in-stock) + in/out of stock, so a listing renders
  "from ₺X" in one round trip. Reads its own data + `InventoryQueryContract`.

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

## 2.3 Design
Reuse the approved **"Enerjik + Kurumsal"** direction and the real product imagery from the
earlier mockups. Responsive, tr-first. Theme-aware where sensible.

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

## Ratification checklist (on approval)
- [ ] Record ADR-058 in the ADR record + amendment log.
- [ ] Confirm the §4 rulings.
- [ ] Build Phase A (backend public surfaces) first — one work order; server-side.
- [ ] Then Phase B (Next.js app) and Phase C (deploy).
