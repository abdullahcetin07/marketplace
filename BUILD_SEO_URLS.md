# Work order — Flat SEO slug URLs (backend) + ADR-059

**Status:** approved by owner 2026-08-03 ("düz / rakip tarzı" scheme, full SEO,
before Payment). **Supersedes the prefixed draft.** Disposable — `git rm` when done.

**Session split:** BACKEND → the **server session**. The frontend (desktop session)
does the catch-all route, the three page types, the real category menu, canonical,
`sitemap.xml`, `robots.txt` and JSON-LD once these endpoints exist. Do **not** touch
`storefront/`.

---

## The scheme (owner-chosen: flat, competitor-style)

Root-level slugs, no `/urun` `/kategori` `/marka` prefixes:

```
Product   →  /avene-cicalfate-hassas-ve-yipranmis-ciltler-icin-bakim-kremi-40-ml
Category  →  /cilt-bakimi
Brand     →  /bioderma
```

For SEO **ranking** a prefix is neutral — Google ignores it — so this is an
aesthetic/brand choice. Its real cost is a **shared namespace**: product, category,
brand and the app's own routes (`/sepet`, `/hesap`, …) all live at the root, so a
slug must be unique across ALL of them and must never equal a reserved route. That
is what the registry and reserved list below buy.

## Why this also fixes a LIVE BUG + the recurring trap

- `?category=Dermokozmetik` **500s** today (browse filter compares a uuid column to a
  name). `/products/{slug}` **500s** (same). Both are the `where('uuid_col', $string)`
  → **SQLSTATE[22P02]** trap — 3rd occurrence (reservation-uuid, geo, now this).
  **Every lookup resolves by shape (uuid-pattern → uuid, else slug) and returns 404
  on miss, never 500.** A pgsql test must assert non-uuid + unknown values 404/empty,
  not 500.

---

## ADR-059 (new — apply to the ADR + the 001 amendment log + Catalog.md, same change)

> **# ADR-059 Flat Human-Readable Slugs Are the Public Storefront Address; a Global
> Slug Registry Guarantees Uniqueness and Redirects**
>
> **Decision.** The storefront addresses product, category and brand by a **flat,
> root-level slug** (`/bioderma`, `/cilt-bakimi`, `/avene-...-krem`) — no type
> prefix. Because all three (plus the app's own routes) share one root namespace, a
> single **slug registry** owns every public slug and enforces uniqueness across the
> three entity kinds and a reserved-word list. A slug is generated from the name
> (Turkish-aware: İ/ı/ş/ğ/ü/ö/ç → i/i/s/g/u/o/c), unique (numeric suffix on
> collision), and **stable once issued** — renaming an entity does not change its live
> slug; when a slug must change, the old one is retained as a non-canonical alias that
> **301-redirects** to the new one.
>
> **A resolver turns a slug into a type.** `GET /resolve/{slug}` returns the entity
> kind + id (or 404), so the storefront's one catch-all route can render the right
> page without guessing.
>
> **#7 intact.** A slug is a *public* identifier like the uuid, never the internal
> auto-increment id. The API keeps uuids; slugs are an additional public key.
> **Every lookup resolves by shape and 404s on miss — never a uuid-cast 500.**
>
> **Cost.** A `slugs` registry table + a reserved-word guard + backfill; slug
> stability logic and a redirect alias trail; a resolver endpoint; two new public
> read surfaces (`/categories`, `/brands`). The shared namespace means a new reserved
> app route must be added to the backend list before the frontend ships it, or a
> product could shadow it.

---

## Backend tasks

### 1. Slug registry (Catalog — the core of this order)

Create a `slugs` table: `{ slug (unique), sluggable_type, sluggable_id, is_canonical,
timestamps }`. Every product, category and brand registers its slug here.

- **Uniqueness is global** — one unique index on `slug` across all three kinds.
- **Reserved words** the slugify must never emit (append a suffix instead): `sepet,
  hesap, odeme, giris, kayit, urunler, magaza, api, admin, seller, store, sanctum,
  livewire, build, storage, sitemap.xml, sitemap, robots.txt, _next, favicon.ico,
  giris-yap, cikis`. Keep this list in one place; it must match the storefront's
  static routes (the frontend will confirm its set).
- **Stability + redirects:** a name change does NOT mutate a canonical slug. If a slug
  is deliberately changed, insert the new one canonical and keep the old row
  `is_canonical=false` pointing at the same entity — the resolver reports it so the
  frontend 301s.
- Turkish-aware slugify (fold diacritics, lowercase, hyphenate, strip punctuation).
- Backfill: products already have a slug string — migrate them into the registry;
  generate + register category and brand slugs.

### 2. Resolver

```
GET /api/v1/resolve/{slug}
  → 200 { type: 'product' | 'category' | 'brand', id, slug, canonical_slug }
  → 404 when no registry row
canonical_slug differs from slug only for a retired alias → frontend 301s to it.
```

### 3. Category endpoints (slug-addressed)

```
GET /api/v1/categories            → active tree: [{ id, name, slug, parent_id,
                                      children: [...], product_count }]
GET /api/v1/categories/{slug}     → { id, name, slug,
                                      path: [{ id, name, slug }],   // breadcrumb
                                      children: [{ id, name, slug, product_count }] }
```
`product_count` = SELLABLE products (≥1 active in-stock offer) in the subtree.

### 4. Brand endpoints (slug-addressed)

```
GET /api/v1/brands           → [{ id, name, slug, product_count }]   (sellable only)
GET /api/v1/brands/{slug}    → { id, name, slug, logo?, product_count }
```

### 5. Product + browse resolve by slug OR uuid, and stop 500ing

- `GET /api/v1/products/{idOrSlug}` — uuid-pattern → uuid, else slug; 404 on miss.
- `GET /api/v1/products?category={slug|uuid}` and `?brand={slug|uuid}` — resolve
  slug→id; category matches the **subtree**; unknown value → empty 200, never 500.
- Expose `slug` on product detail's `category` + every `path` node, and on `brand`.

### 6. Tests

- pgsql: `/resolve/{productSlug|categorySlug|brandSlug}` returns the right type;
  `/resolve/made-up` 404; `/products/{slug}` 200, `/products/{unknown}` 404;
  `?category={slug}` 200, `?category=made-up` empty 200 — **none 500**.
- slugify: Turkish folding, collision suffix, reserved-word avoidance, stability on
  rename, alias→canonical redirect reporting.
- `/categories`, `/categories/{slug}`, `/brands`, `/brands/{slug}` shapes.
- `make check` green. Report: registry table, backfilled counts, reserved list,
  endpoint signatures, and the uuid/slug guard on each lookup.

## Boundaries

- Catalog is NOT frozen — these are Catalog additions; the registry is Catalog-owned
  (product/category/brand are all Catalog). `LayeringTest` / `CatalogBoundaryTest`
  stay green (slugs carry no price/stock). UUIDs still public; slugs additional (#7).

## Frontend follow-up (desktop session — NOT you)

Once these land: a single catch-all `/[slug]` that calls `/resolve`, then renders the
product / category / brand view (404 unknown, 301 to `canonical_slug`); Next's static
routes (`/sepet`, `/hesap`, …) naturally take precedence over the catch-all. Plus the
real category menu from `/categories`, `<link rel=canonical>`, `sitemap.xml` +
`robots.txt`, and Product/BreadcrumbList JSON-LD. Nothing for you in `storefront/`.
