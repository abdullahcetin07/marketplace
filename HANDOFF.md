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

### `BUILD_LOYALTY_P1.md` — Loyalty (customer points), Phase 1 (ADR-081/082/083)

A NEW module: customer points. **Phase 1 only** — earning + an append-only ledger
+ an admin "Puan Ayarları" page + a customer read API. **Redemption at checkout is
Phase 2 (ADR-084) and is explicitly out of scope** — build no `LoyaltyContract`, no
checkout change.

- Standalone `Loyalty` module, **imports no module** (`LayeringTest`); append-only
  ledger, **balance computed on read** (no `balance` column).
- Earns three ways by **class-string** listeners / a sweep: signup (once),
  `ReviewPublished` (once per review), and **purchase** — a daily
  `loyalty:award-purchase-points` sweep over delivered seller-orders past their
  return window, on the KDV-included paid TL. Needs **one Core `OrderQueryContract`
  addition** (`pointsEligibleSellerOrders(asOf)`).
- Rates + point value are **`settings()`** on one audited Filament page
  (Admin/Finance), defaults = **5% back**. A point is an integer count; the value is
  a DECIMAL rate.
- **The scheduler is part of the feature** — the sweep is inert without cron (the
  ADR-072 lesson); confirm it runs and say so.

Full spec: **`docs/modules/Loyalty.md`**. Work order: **`BUILD_LOYALTY_P1.md`**.
Decisions: **ADR-081–084** + amendment log #19. Delete this section once P1 lands
and is verified; Phase 2 (redemption) will be queued as its own work order.

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
