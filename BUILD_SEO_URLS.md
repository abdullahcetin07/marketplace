# Work order — SEO-friendly public URLs (backend) + ADR-059

**Status:** approved by owner 2026-08-03 ("şimdi, tam SEO", before Payment).
Disposable — `git rm` this file when the work lands.

**Session split:** BACKEND → the **server session**. The frontend (desktop session)
does the slug routes, category/brand pages, canonical tags, `sitemap.xml`,
`robots.txt` and JSON-LD once these endpoints exist. Do **not** touch `storefront/`.

---

## Why (and a LIVE BUG this also fixes)

Today's public URLs are not addressable the way a search engine or a human wants,
and one of them is outright broken:

- Product is `/urun/{uuid}` — the API only resolves a product by uuid, so the slug
  it already has (`laborum-iure-...`) is unusable in a URL.
- Category links are `/urunler?category=Dermokozmetik` — the browse filter expects a
  **uuid** and **500s on a name** (`?category=Dermokozmetik` → 500 live right now).
  The home page's category shortcuts are therefore dead links.
- Categories and brands have **no slug**, and there is **no `/categories` or
  `/brands` endpoint** (both 404) — the storefront can't even list the real taxonomy.
- There is no brand page at all.

### THE UUID-vs-STRING 500 IS THE SAME TRAP, NOW THE 3RD TIME

`/products/{slug}` → 500 and `?category={name}` → 500 are both
`where('uuid_col', $string)` → **SQLSTATE[22P02]** on pgsql (SQLite returns false
silently, so the suite stays green). Same class as the reservation-uuid bug and the
geo bug. **Every lookup in this order must decide uuid-or-slug by shape and return
404 on miss, never 500.** Add a pgsql feature test that asserts a non-uuid slug and
an unknown value both resolve or 404 — not 500.

---

## ADR-059 (new — apply to the ADR + the 001 amendment log + Catalog.md, same change)

> **# ADR-059 Human-Readable Slugs Are the Public Storefront Address**
>
> **Decision.** Product, category and brand are addressable in the storefront by a
> unique **slug**, and the public read surface resolves a lookup by **slug OR uuid**.
> Slugs are generated from the name (Turkish-aware: lowercased, diacritics folded —
> "Cilt Bakımı" → `cilt-bakimi`), unique within their kind (a numeric suffix breaks
> ties), and stable once issued. The storefront's canonical URL for an entity uses
> its slug; a uuid URL 301-redirects to it (the frontend does the redirect).
>
> **This does not weaken non-negotiable #7.** #7 forbids leaking the internal
> auto-increment `id`; a slug is a *public* identifier like the uuid, not the
> internal key. The API keeps uuids; slugs are an additional public lookup key.
>
> **Every public lookup resolves by shape:** a parameter matching the uuid pattern is
> looked up by uuid, otherwise by slug; a miss is 404. Passing a name/slug where a
> uuid was assumed must never reach the database as a uuid comparison (the
> SQLSTATE[22P02] class of bug).
>
> **Cost.** A slug column + unique index + backfill on products (already sluggable),
> categories and brands; a slugify that must stay stable (renaming a category does
> not silently change its URL — a new slug needs a redirect, a follow-up); two new
> public read endpoints (`/categories`, `/brands`) and slug-or-uuid resolution on
> product detail and the browse filters.

---

## Backend tasks

### 1. Slugs on category + brand (Catalog — not frozen)

- Add `slug` (string, unique) to categories and brands. Generate from name with a
  **Turkish-aware** slugify (fold İ/ı/ş/ğ/ü/ö/ç → i/i/s/g/u/o/c), unique with a
  numeric suffix on collision. Backfill all existing rows.
- Expose `slug` everywhere the entity already appears: product detail `category` +
  every node of `category.path`, `brand`, and the new endpoints below.
- Slug is auto-generated now; **operator-editable slug is a follow-up**, not this
  order. Renaming a name must NOT auto-change an existing slug (URL stability).

### 2. Public category endpoints

```
GET /api/v1/categories
    → the active category tree, each node:
      { id, name, slug, parent_id, children: [...], product_count }
    product_count = count of SELLABLE products (≥1 active in-stock offer) in the
    subtree — the same "sellable" rule the browse listing already uses.

GET /api/v1/categories/{slug}
    → { id, name, slug,
        path: [{ id, name, slug }, ...],      // root → self, for breadcrumb
        children: [{ id, name, slug, product_count }] }
```

Read through the existing Core `CatalogQueryContract` where it fits; no new
cross-module import (Catalog owns this). ADR-009 envelope, anonymous, cache-friendly.

### 3. Public brand endpoints

```
GET /api/v1/brands            → [{ id, name, slug, product_count }]  (sellable only)
GET /api/v1/brands/{slug}     → { id, name, slug, logo?, product_count }
```

### 4. Product resolve by slug OR uuid

- `GET /api/v1/products/{idOrSlug}` — resolve by uuid when the param matches the uuid
  pattern, else by slug; **404 on miss, never 500**. (Frontend will canonicalize a
  uuid hit to the slug URL via 301, so keep both working.)

### 5. Browse filters accept slug OR uuid — and stop 500ing

- `GET /api/v1/products?category={slug|uuid}` and `?brand={slug|uuid}` — resolve
  slug→id internally; an unknown or malformed value returns an **empty page**, not a
  500. This fixes the live category-link break.
- Category filter should match the **subtree** (a parent category lists its
  descendants' products), which is what a category landing page expects.

### 6. Tests

- pgsql feature test: `/products/{slug}` 200; `/products/{unknown}` 404;
  `?category={slug}` 200; `?category=made-up` empty+200; **none 500**.
- `/categories`, `/categories/{slug}`, `/brands`, `/brands/{slug}` shapes.
- slugify: Turkish folding + collision suffix + stability on rename.
- `make check` green. Report: migrations, backfilled row counts, endpoint
  signatures, and confirm the uuid/slug guard on each lookup.

## Boundaries

- Catalog is NOT frozen (Offer/Inventory/Order reach into it) — these are Catalog
  additions. `LayeringTest` / `CatalogBoundaryTest` stay green (slugs carry no price
  or stock). UUIDs still public; slugs are an additional public key (#7 intact).

## Frontend follow-up (desktop session — NOT you)

Once these land, the desktop session builds: `/urun/{slug}` (uuid→slug 301),
`/kategori/{slug}`, `/marka/{slug}`, a real category menu from `/categories`,
`<link rel=canonical>`, `sitemap.xml` + `robots.txt`, and Product/BreadcrumbList
JSON-LD. Nothing for you in `storefront/`.
