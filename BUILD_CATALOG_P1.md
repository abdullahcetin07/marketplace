# Work order — build the Catalog module, Phase 1

**Disposable. Delete when done.** For the server-side Claude session (has `vendor/`,
runs the suite + the app). Build the **Catalog module Phase 1** exactly to the APPROVED
spec. This is the FIRST new module since the Org/Store freeze — mirror the established
modules precisely. Work sub-phase by sub-phase, commit each, keep the suite green
(currently ~433 passed / 0 failed), push. Run inside tmux so a dropped SSH doesn't kill you.

## Source of truth (read first, in full)
- **`docs/modules/Catalog.md`** — the approved spec. Every decision, model, rule, event,
  contract, permission, and the §13 rulings live there. Follow it; do not re-decide.
- **Precedence chain** (CLAUDE.md → ADR → 001/003/002/004/005 → module spec). Where the
  spec is silent, follow the general docs and the existing modules.

## Mirror these (structure + conventions — copy the shape, not the domain)
- `app/Modules/Organization/**` and `app/Modules/Store/**` for the
  Domain/Application/Infrastructure/Presentation layout, the ServiceProvider, repository
  pattern, DTOs, events, policies, Filament per-panel resources, and tests.
- Non-negotiables apply (enforced by tests, not convention): `declare(strict_types=1)`
  everywhere; **modules never import each other** (events + Core contracts only —
  `LayeringTest`); Domain imports no Eloquent/Request/DB facade and no
  `cache()/request()/encrypt()`; DTOs suffixed `DTO` in `{Module}/Domain/DTOs`; roles by
  NAME via `config('marketplace.roles.*')`; policies check permissions not roles; public
  ids are UUIDs, internal `id` never leaves; no `dd/dump/die/exit`; Audit entries
  append-only. **No price and no stock anywhere in Catalog** (spec §0.2) — that is Offer.

## Sub-phases (commit + keep green after each)

### P1 — ratify the ADRs + scaffold
- Add **ADR-037 … ADR-041** to `docs/Architecture_Decision_Record.md` and their entries
  to the amendment log at the end of `docs/001_Architecture.md`, in the SAME change —
  match the existing ADR/amendment format exactly (read a few existing entries first).
  The five decisions are stated verbatim in `docs/modules/Catalog.md` §0.3–§0.8/§13.
- Scaffold `app/Modules/Catalog/` (the four layers), a `CatalogServiceProvider`
  registered like the other module providers, `config/catalog.php` if the module needs
  config, and a module `README`? — NO: per CLAUDE.md modules are documented in
  `docs/modules/Catalog.md`, not a README inside the module. Add `Catalog` to
  `app/Modules/README.md` (the module index) and flip `docs/modules/Catalog.md` status
  note to "building". Ensure `LayeringTest` covers the new module.

### P2 — Domain
Models (spec §2): `Category` (tree; adjacency + materialised `path`), `Brand`,
`Attribute` + `AttributeValue`, `Product` (aggregate root, **Auditable**,
`proposed_by_org_uuid`, own moderation `status`, HasMedia gallery), `ProductVariant`
(SKU, HasMedia), plus the pivots (`category_attribute`, `product_attribute_value`,
`variant_attribute_value`). Enums (§2.6, no `Enum` suffix): `ProductStatus`,
`AttributeType`. DTOs. Domain events (§7). The Core read contract
`App\Core\Domain\Contracts\CatalogQueryContract` (mirror `StoreQueryContract`).

### P3 — Infrastructure
Migrations for every table above; repositories (declare eager loads on `$with`, strict
mode is on); factories for all models; a **starter taxonomy seeder** (§13.3) — a small
editable top-level category tree + a few common attributes.

### P4 — Application
Actions (spec §12): taxonomy (`CreateCategory`, `UpdateCategory`, `ReorderCategories`,
`ArchiveCategory`, `BindCategoryAttribute`, `CreateAttribute`, `CreateAttributeValue`,
`CreateBrand`); products (`DraftProduct`, `UpdateProduct`, `SetProductAttributes`,
`GenerateVariants`/`UpsertVariant`, `AttachProductMedia`, `SubmitProductForReview`,
`PublishProduct`, `RejectProduct`, `RequestProductRevision`, `ArchiveProduct`). One
transaction per action; events + search indexing in `BaseAction::after()` (after commit).
Policies + permissions registered via `PermissionRegistry` (then `make permissions`),
attached in `RolePermissionSeeder`: `catalog.taxonomy.manage` and
`catalog.products.moderate` (Category Manager, Admin), `catalog.products.author` (Seller,
own only), `catalog.products.viewAny`. Enforce the lifecycle + integrity rules (spec §3).

### P5 — Presentation (Filament, per panel)
- **Admin panel** (Category Manager): taxonomy resources (categories tree, attributes,
  brands) + a **product moderation queue** (pending products → approve / reject / request
  revision with reason; view the product, its variants, attributes, images).
- **Seller panel** ("ürün aç", spec §5 entry point 2): author a product — pick a leaf
  category, fill localized title/description, set attributes, **generate variants**
  (cartesian from selected variant-defining values, prunable), upload images, submit for
  review. **Membership-scoped** to `proposed_by_org_uuid` — a seller only ever sees/edits
  their own proposals (same wall as the Org/Store seller resources). Register resources
  explicitly per panel; never discover from a shared root.
- Do NOT surface the "select an existing product to sell / price / stock" path — that is
  Offer (Phase 2). Nothing store-facing or customer-facing in Phase 1.

### P6 — Search + wiring
Index products on `ProductPublished`, drop on `Archived` (Scout → the configured engine).
Bind `CatalogQueryContract` in the provider. Wire Activity/Audit listeners consistent
with the other modules. No storefront contributor in Phase 1 (spec §0.8).

### P7 — Tests, docs, sweep
Feature/unit tests per layer mirroring the Org/Store test style: taxonomy management,
attribute/category binding, product draft→submit→publish/reject/needs-revision,
variant uniqueness + generation, GTIN/slug uniqueness, seller membership scoping (a
seller cannot touch another's proposal), permission gating (Category Manager vs seller vs
admin), and a Filament/Livewire test for the "ürün aç" page and the moderation queue.
Keep the whole suite green. Import/strict-types sweep.

## Live end-to-end verify (browser, or drive the Livewire components if no browser)
As a **seller** at `/seller`: "ürün aç" → category + attributes + variants + image →
submit. As a **Category Manager / admin** at `/admin`: open the moderation queue → review
→ **approve** → product is `Published` and indexed. Confirm a second seller cannot see the
first seller's draft. No 500s; no price/stock anywhere.

## Finish
- One commit per sub-phase (P1…P7), push `origin main` (the seller cannot push for you —
  the human pushes; leave the commits ready and say so if push is unavailable).
- `php artisan test` → 0 failed; report the final `Tests:` line and a short live-verify note.
- `git rm BUILD_CATALOG_P1.md`, commit, push.
