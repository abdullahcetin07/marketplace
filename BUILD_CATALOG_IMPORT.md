# BUILD — Admin bulk catalogue import (Excel/CSV → categories/brands/products/variants/images)

**ADR:** ADR-074. **Owner-approved.** Admin self-service upload; **catalog only** (no
offers/price/stock — those are a later, separate import). **v1 = one default variant per
product**; colour/size variant axes + descriptive attributes are **phase 2**.

**What it does:** an admin uploads an Excel/CSV; each row becomes a category (by path) +
brand + product + one default variant + tax bracket + images (from URLs), **published**.
Queued, chunked, with a per-row failure report. Dedup by GTIN.

**It DRIVES the existing authoring actions — it does NOT write models** (ADR-074). Bypassing
them would skip moderation, the slug registry, the GTIN guard, `combination_key`, and the
events other modules consume.

Build the phases in order; each ends `make check` green + commit + push. Contradiction →
STOP and report (ADR-018).

---

## Non-negotiables (restated)

- `declare(strict_types=1)`; no `dd/dump/die`. **UUIDs across boundaries, never internal id.**
- **Go through the Catalog authoring actions** (below). No direct `Product::create` in the importer.
- **A bad row is recorded and skipped, never fatal to the batch** (Filament `failed_import_rows`).
- **Idempotent**: re-running the same file updates by GTIN, does not duplicate.
- `make check` green before any phase is "done".

---

## Verified facts (don't re-derive)

- **Tooling:** `filament/filament ^3.3` is installed and bundles `league/csv` + `openspout`
  (via `filament/actions`) — CSV **and** xlsx parsing, **zero new deps**. Filament's `Importer`
  needs its two tables published: `php artisan vendor:publish --tag=filament-actions-migrations`
  (creates `imports` + `failed_import_rows`). No import code exists yet.
- **Admin panel:** `app/Providers/Filament/AdminPanelProvider.php` — resources registered
  explicitly; **Pages auto-discovered** in `app/Filament/Admin/Pages/`. Attach an
  `Filament\Actions\ImportAction` to an admin resource table header (a new `ProductResource`, or
  the existing `ProductModerationResource`), OR a custom page. Nav group `nav.catalogue`.
- **Authoring actions** (`app/Modules/Catalog/Application/Actions/`, all `BaseAction`,
  `->run(...)` wraps `handle()` in a transaction, events in `after()`):
  - `DraftProductAction::handle(DraftProductDTO): Product` — the ONLY product-creation entry
    (status `Draft`). `DraftProductDTO(string $categoryUuid, array $title, array $description = [],
    ?string $brandUuid = null, ?string $taxRateUuid = null, ?string $gtin = null, ?string $slug = null,
    ?int $proposedByOrgId = null, ?string $proposedByOrgUuid = null, ?int $proposedByUserId = null)`.
    `title`/`description` are **locale maps** `['tr' => '...']`. It calls `findByGtin` and throws
    `gtinAlreadyInCatalog` on collision; normalises empty gtin → null.
  - `UpsertVariantAction::handle(Product, UpsertVariantDTO): ProductVariant`.
    `UpsertVariantDTO(array $valueUuids = [], ?string $variantUuid = null, ?string $sku = null,
    ?string $barcode = null, ?int $position = null, bool $isDefault = false)`. **Empty `valueUuids`
    = the single default variant** (SKU auto-generated). This is v1's variant.
  - `SubmitProductForReviewAction::handle(Product): Product` — enforces **≥1 variant AND non-null
    tax_rate**.
  - `PublishProductAction::handle(Product, ?ModerationDecisionDTO): Product` — needs
    `catalog.products.moderate` (Admin has it) + **all required descriptive attributes present**
    (fresh categories have none → passes). `ModerationDecisionDTO(moderatedBy: adminId)`.
  - `CreateCategoryAction::handle(CreateCategoryDTO): Category`.
    `CreateCategoryDTO(array $name, ?string $parentUuid = null, ?string $slug = null,
    bool $isActive = true, bool $acceptsProducts = false, ?int $position = null)`.
  - `CreateBrandAction::handle(CreateBrandDTO): Brand`. `CreateBrandDTO(string $name, ?string $slug = null,
    bool $isActive = true)`. **Does NOT dedup** — you dedup before calling.
- **Category by path:** no built-in path helper. Walk `A > B > C`: per segment `findBySlug(Str::slug($segment))`
  on `CategoryRepositoryContract` (has `findBySlug`, `findByUuid`, `slugExists`, `roots`, `tree`);
  if absent, `CreateCategoryAction` with the previous segment's uuid as `parentUuid`. **The LEAF must
  be `acceptsProducts = true`** (ADR-047) or `DraftProductAction` throws — set it on the last segment
  (and, if you reuse an existing non-leaf, that's an error row).
- **Brand dedup:** `BrandRepositoryContract::findBySlug(Str::slug($name))` → reuse, else `CreateBrandAction`.
- **Product:** `gtin` is `UNIQUE` (14). `ProductRepositoryContract::findByGtin(string): ?Product` +
  `gtinExists`. Dedup: `findByGtin` → if found, update title/category/brand/tax (a small update path,
  or skip) rather than create.
- **tax_rates:** `TaxRate` model, `code` unique (`kdv-20`/`kdv-10`/`kdv-1`/`kdv-0`), `rate` decimal ratio
  (`0.2000`). No repo. Map the cell: strip `%`, `20 → kdv-20` by code, or `where('rate', number_format($ratio,4))`;
  fallback `TaxRateSeeder::DEFAULT_CODE = 'kdv-20'`. Feed `TaxRate->uuid` into `DraftProductDTO::taxRateUuid`.
- **Images from URL:** `$product->addMediaFromUrl($url)->toMediaCollection('images')` (Spatie, via
  `App\Shared\Traits\HasMedia`; **not** wrapped in a DB transaction — mirror `AttachProductMediaAction`'s
  `$useTransaction = false`). Only jpeg/png/webp/avif accepted (gif rejected → log + continue).
  Conversions are queued (Horizon `media` queue).
- **Admin provenance:** `proposed_by_org_uuid`/id/user null for an admin importer (nullable; "provenance,
  not ownership").
- **Jobs:** `BaseJob` (extends, **must call `parent::__construct()`**). No `Bus::batch()` in the codebase
  yet — Filament's `Importer` already chunks + queues rows onto its own jobs.
- Tests: **Pest**, Feature/Modules get `RefreshDatabase`; `seedPlatform()` (seeds tax_rates), `actingAsAdmin()`.

---

## Phase C1 — Filament import scaffolding + the row logic service

- Publish Filament's import migrations: `php artisan vendor:publish --tag=filament-actions-migrations`
  → commit the generated `imports` + `failed_import_rows` migrations under `database/migrations/`.
- Create **`app/Modules/Catalog/Application/Import/CatalogRowImporter.php`** — a service holding ONE
  method that processes one parsed row and returns the created/updated Product (or throws a
  row-level exception with a Turkish message for the failure report):
```php
/** @param array<string,string|null> $row  keyed by the mapped columns */
public function import(array $row, int $adminId): Product;
```
  Steps inside `import()`:
  1. `resolveCategoryByPath((string) $row['kategori_yolu'])` → leaf Category (acceptsProducts) — a
     private helper that walks segments (findBySlug / CreateCategoryAction), leaf `acceptsProducts=true`.
  2. `resolveBrand($row['marka'])` → ?Brand uuid (findBySlug / CreateBrandAction; null if blank).
  3. `resolveTaxRate($row['kdv'])` → TaxRate uuid (map by code/rate; default kdv-20).
  4. Dedup: `products->findByGtin($gtin)` (when gtin present). If found → this is an update (v1: update
     title/category/brand/tax, keep its variant); else → `DraftProductAction::run(new DraftProductDTO(
     categoryUuid, title: ['tr' => $baslik], description: ['tr' => $aciklama ?? ''], brandUuid, taxRateUuid,
     gtin: $gtin, proposedBy*: null))`.
  5. New product only: `UpsertVariantAction::run($product, new UpsertVariantDTO())` (empty valueUuids = default variant).
  6. Images: for each pipe-split URL in `$row['gorsel_url']`, `$product->addMediaFromUrl($url)
     ->toMediaCollection('images')` in try/catch (a bad URL logs + continues — never fails the row).
  7. New product only: `SubmitProductForReviewAction::run($product)` then
     `PublishProductAction::run($product, new ModerationDecisionDTO(moderatedBy: $adminId))`.
  8. return $product.

  Throw a typed `CatalogImportException` (extends `BaseException`, `$reportable=false`) with a clear
  message for the row-failure report on any unrecoverable row error (bad category, no title, publish
  gate failure) — Filament records it in `failed_import_rows`.

**Tests (`tests/Modules/Catalog/Feature/CatalogRowImporterTest.php`):**
- a full row creates category (by path) + brand + product + default variant + tax, **Published**.
- re-importing the same gtin does NOT create a second product (dedup/update).
- a missing `baslik` throws a recorded row error; a missing category path throws; other rows unaffected.
- an image URL is fetched and attached (mock the HTTP fetch).

**Steps:** publish migrations → service → helpers → tests → `make check` → commit + push
`feat(catalog): CatalogRowImporter — one row → category/brand/product/variant/images (ADR-074)`.

---

## Phase C2 — The Filament importer + upload UI

- **`app/Modules/Catalog/Presentation/Filament/Imports/ProductImporter.php`** extends
  `Filament\Actions\Imports\Importer` (`protected static ?string $model = Product::class`):
  - `getColumns()`: the importable columns with labels + rules — `baslik` (required), `kategori_yolu`
    (required), `marka`, `gtin`, `aciklama`, `kdv`, `gorsel_url`. Use `ImportColumn::make('...')
    ->requiredMapping()->rules(['required'])` for the two required ones; the rest optional.
  - **Delegate the actual work to `CatalogRowImporter`.** Filament's `resolveRecord()` is model-centric;
    the clean fit is to override `resolveRecord()` to run `app(CatalogRowImporter::class)->import($this->data,
    $this->import->user_id)` and return that Product (Filament then treats it as the saved record). If the
    model-record shape fights the multi-entity creation, use a bespoke queued page instead (see note) —
    but Filament's `Importer` is the recommended route for the free chunking + `failed_import_rows`.
  - `getCompletedNotificationBody()`: "N ürün içe aktarıldı, M satır başarısız." (Turkish).
- **Attach the import to the admin panel:** add `Filament\Actions\ImportAction::make()->importer(ProductImporter::class)`
  to the header actions of an admin resource table — either a new minimal `ProductResource` (list +
  import) under `app/Modules/Catalog/Presentation/Filament/Resources/`, or the existing
  `ProductModerationResource`. Register the resource in `AdminPanelProvider`'s `->resources([...])`
  (nav group `nav.catalogue`). Gate on `catalog.products.moderate`.

> **Note for the server:** if Filament's one-row-one-model `Importer` is awkward for the multi-entity
> creation, implement a bespoke admin **Page** (`app/Filament/Admin/Pages/ImportCatalog.php`,
> auto-discovered) with a `FileUpload` + a queued `BaseJob` that reads the file via `openspout` and
> loops rows through `CatalogRowImporter`, writing failures to a report. Either way the row logic (C1)
> is identical — pick the cleaner UI host.

**Tests:** an admin can reach the import action (permission); a non-admin cannot; a small uploaded
CSV runs end-to-end and the products appear Published (feature test driving the importer).

**Steps:** importer → resource/action → register → tests → `make check` → commit + push
`feat(catalog): admin Excel/CSV product import UI (ADR-074)`.

---

## Phase C3 — Template + hardening + docs

- **Downloadable template:** a "Örnek şablon indir" action/link that returns a CSV/xlsx with the
  header row (`baslik, kategori_yolu, marka, gtin, aciklama, kdv, gorsel_url`) + one example row.
  (Filament `ImportAction` has `->downloadableExampleFileContent()`/example-file support — use it.)
- **Queue:** ensure the import + media conversions run on Horizon-supervised queues; the import is
  inert without a running worker (same money/ops-critical scheduler note as the sweeps).
- Full `make check`; `LayeringTest` (the importer is Catalog-internal; it drives Catalog actions and
  uses no other module).
- Docs: `docs/modules/Catalog.md` — a section on the bulk import + the column schema + the v1 scope
  (single default variant; attributes/axes phase 2). Amendment log row for ADR-074.
- Commit + push `feat(catalog): import template + docs (ADR-074)`.

---

## Deploy (server)

```bash
git pull
php artisan migrate            # imports + failed_import_rows
php artisan optimize:clear
# Ensure a queue worker is running (Horizon or queue:work) — the import + image fetches are queued.
```

Then in `/admin`: the catalogue resource shows an **"İçe Aktar"** action → upload the Excel → map the
columns → the rows process in the background → a completion notice + a downloadable failed-rows file.

## Out of scope (v1 — phase 2 / later)

- **Variant axes** (colour/size) + **descriptive attributes** — need the moderated category
  attribute schema (ADR-038); a spreadsheet cell must not silently create schema.
- **Offers (price/stock)** — a separate seller-facing import (the owner chose catalog-first).
- **Mapping into existing categories that already have required attributes** — v1 creates fresh
  categories (no required attrs); a row targeting a required-attr category will fail publish and be
  reported (acceptable).
- **Scheduled/feed sync** (supplier XML/API) — a future capability; v1 is a manual upload.
