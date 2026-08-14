# Handoff — server-side backend work

**Disposable working note.** A Claude Code session running ON THE SERVER
(`/var/www/www.raftabul.com/test`, bare-metal PHP 8.3, PostgreSQL/Redis, **no
Docker**) reads this to pick up backend work the desktop (frontend) session
queued. You are autonomous here: run the suite yourself, fix root causes, commit
each fix separately, push.

The frontend is already built against every contract below and **degrades
cleanly until the backend lands** — nothing breaks in production while this is
pending.

---

## ▶ ACTIVE work order

### `BUILD_LISTING_FILTERS.md` — listing filters: price range + brand facet (ADR-080)

Storefront category/brand/search listings gained a URL-driven filter bar
(pushed, live). It needs the browse endpoint to supply the data:

- `GET /api/v1/products` (`PublicProductBrowse`) gains **`price_min` / `price_max`**
  request params — decimal-string TL, converted to minor units at the boundary,
  applied against the buy-box (Offer) price (the same price `price_asc/desc`
  already sorts by).
- Response `meta` gains a **`facets`** block: `brands` (each with a `count`,
  ordered count-desc, cap ~40) + `price` `{min,max}` as decimal strings.
- **Faceting rule:** compute facets over the query **minus the applied
  `brand`/`price_min/max`**; `category` and `q` DO scope them.
- **Boundary:** price + price facet through `OfferQueryContract` (Catalog imports
  no Offer — `LayeringTest` + `CatalogBoundaryTest` stay green). Brand facet is
  Catalog's own. Stay on the **`is_sellable`** indexed path (ADR-079) — do NOT
  reintroduce the per-offer walk.
- Tests + timing note in the file. No migration (read-only). `make check` green,
  then deploy (`git pull`, `optimize:clear`).

Full spec: **`BUILD_LISTING_FILTERS.md`** at repo root. Decision: **ADR-080**
(`docs/Architecture_Decision_Record.md`) + amendment log #18
(`docs/001_Architecture.md`).

Delete this section (or the whole file) once it's landed and verified live on a
big category (e.g. `/cilt-bakimi`: pick a price range + brand, grid and total
narrow, URL carries the filters).

---

## How to work (autonomous)

- **Run the suite yourself**: `php artisan test`. The Pest suite runs against
  sqlite `:memory:` (phpunit.xml) — it does NOT touch the app's Postgres, so
  schema works on both paths. Edits and tests are the same filesystem; no git
  round-trip needed. Commit each fix (small, one root cause per commit) and push.
- To see the real exception behind a 500 (Pest hides it):
  `php artisan test --filter="<part of test name>" 2>&1 | tail -50`, or
  `grep -iE "Exception:|Attempted to lazy load|not retrieved" storage/logs/laravel.log`.

## Binding rules (do not violate)

1. **Execution-driven only.** Fix a failure only after you have SEEN it fail and
   read its real exception. No speculative refactors; do not refactor working code.
2. **Sprint/work-order never overrides docs** (ADR-018). If `BUILD_LISTING_FILTERS.md`
   contradicts the ADR chain, STOP and report — don't pick a side silently.
3. **NEVER edit `tests/Feature/Auth/GuardIsolationTest.php` to make it pass** — a
   failure there is a privilege-escalation bug; fix the code (CLAUDE.md).
4. **Frozen modules** (Identity v2.0, Organization v1.0, Store v1.0): only
   bug/security/compat fixes. Catalog/Offer are NOT frozen — this work touches
   the browse read path + `OfferQueryContract`, which is fair game.
5. **Boundary is enforced by tests, not convention.** `LayeringTest` +
   `CatalogBoundaryTest` fail the build on a leak. Price/stock stays out of
   Catalog; Catalog reads Offer only through the Core contract.
6. **Strict mode is ON in tests** (`Model::shouldBeStrict`): lazy-load /
   missing-attribute throws in tests but only logs in production. Declare eager
   loads on the repository `$with`, not at the call site. A test only catches
   lazy-load with TWO+ rows.

## History

First integration (test-suite green-up) is **COMPLETE** — suite green
(357 passed / 0 failed). That earlier handoff content has been retired; this file
now tracks the current pending work order above.
