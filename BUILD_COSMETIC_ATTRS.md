# Work order — seed cosmetic attributes + disable Scout for now

**Disposable. `git rm` when done.** For the server-side session (backend/ops). Owner-approved.
Two small tasks so the storefront product page fills out and the queue stops piling up.
**Do not touch `storefront/`** (frontend = desktop session). Suite green.

## A — Seed a cosmetic attribute schema + demo values
The product-detail attribute table is empty because the cosmetic categories carry **no
attribute schema** — the platform only has clothing/general attributes (Renk, Beden,
Malzeme, Garanti Süresi). Attributes are per-category (Category Manager defines, seller
values at authoring — ADR-038). Seed a small **descriptive** schema so the demo matches the
design; **the owner will curate the real categories later**, so keep this idempotent and
clearly a demo seed.

1. On the cosmetic categories the demo products sit in (the server's earlier report named
   **Cilt Bakım / Anti Aging / Kırışıklık Karşıtı**), add **descriptive** attributes:
   - **Cilt Tipi** (e.g. Select: Kuru, Yağlı, Karma, Hassas, Kuru & Atopik, Normal)
   - **Hacim** (Text — "500 ml", "40 ml", "30 ml")
   - **Kullanım** (e.g. Select: Yüz, Vücut, Saç, Göz Çevresi)
   - **Menşei** (Text — "Fransa", "Türkiye", …)
   These are **descriptive**, not variant-defining (they must not create SKUs).
2. Attach sensible values to the **real** demo products, at least:
   - **Bioderma Atoderm** → Cilt Tipi "Kuru & Atopik", Hacim "500 ml", Kullanım "Vücut",
     Menşei "Fransa".
   - **Cerave Retinol Serum** → Cilt Tipi "Karma", Hacim "30 ml", Kullanım "Yüz", Menşei "ABD".
   And a couple of the Faker demo products, so the table demonstrates on more than one page.
3. Idempotent seeder (or an authoring pass) — re-runnable, safe. Do **not** delete the Faker
   products (owner: keep them for now).

Report: which attributes + which products got values, and confirm the ongoing rule for the
owner — **define the attribute on the category first, then the value shows up in the seller's
authoring form**; that is how the owner adds attributes to the real categories later.

## B — Disable Scout indexing for now (owner-approved)
OpenSearch is not on this box, so search-index jobs wait on Redis forever. The customer side
does not depend on it (public browse + seller catalog read Postgres directly).
- Set **`SCOUT_DRIVER=null`** in `.env`, then `php artisan config:clear`.
- Confirm Horizon no longer accrues stuck `Scout\...` / OpenSearch jobs, and that
  browse/search on the storefront still returns results (Postgres-backed).
- **Do NOT remove the Scout wiring** — this is a switch, not a removal. Standing up OpenSearch
  and flipping the driver back is a **future performance task** (the owner wants search on a
  real engine later; keep the code ready for it).

## Finish
`git rm BUILD_COSMETIC_ATTRS.md`, commit. Report A (attributes + values) and B (Scout off,
queue clean). If a category-schema change conflicts with a frozen decision, STOP and report
(ADR-018) — but descriptive attributes on a category are ordinary Catalog content, not frozen.
