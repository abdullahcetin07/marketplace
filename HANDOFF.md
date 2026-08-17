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

### `BUILD_LOYALTY_P2.md` — redemption at checkout — **CODE DONE, AWAITING SANDBOX BROWSER VERIFICATION**

All of P2 is built, tested and deployed (2026-08-17). `make check` green; 1,670+
tests. Commits: `3c28fc6` (Core port), `8c70fbd` (quote + hold + callback),
`22b0446` (refund re-credit + cash earn base), `41a24dd` (points-only path),
`2c138f9` (pgsql uuid fix).

**What is left is a browser, not code.** Verify on the PayTR **sandbox**
(`test_mode = true`, confirmed present in `.env` and carried into the hash — no
real charge is possible without changing it):

1. A cart + a points balance → apply points → the PayTR iFrame amount is the
   reduced figure → callback commits one `−points` row.
2. **✅ VERIFIED LIVE (2026-08-17).** The **`payable == 0`** path: a 100-point
   balance covered a ₺5,00 cart in full. Quote showed −₺5,00 / payable ₺0,00; pay
   settled points-only (no iFrame), redirected to `/odeme/sonuc` ("₺0,00 tahsil
   edildi"), and the ledger committed **−100 "Harcama"** → balance 0. Storefront
   `paid`-branch redirect works.
3. **🐛 BUG FOUND — refund of a points-only order 500s.** Admin refunding the very
   order from #2 (points-funded, **`amount_minor = 0`**, `provider_reference =
   'points'`, no card money, no PayTR `merchant_oid`) returns **500 Server Error**.
   This is the one path P2's tests apparently didn't cover against a real refund.
   **Read `storage/logs/laravel.log` for the actual exception** and fix. Prime
   suspects: the refund action calls `PayTrGateway::refund()` with a **0.00 amount**
   (PayTR rejects zero) or dereferences a **null gateway/merchant_oid** that a
   points-only payment never had; or the re-credit fraction divides by a **zero card
   denominator** (a full refund should be 1.0 outright, per your own HANDOFF note —
   verify that branch is actually taken when the card charge is 0). The fix: a
   points-only order has **no card refund to make** — skip the gateway entirely and
   only run `LoyaltyContract::reverse()` to return the points. Add a test:
   refunding an `amount_minor = 0` points-only order re-credits the points and calls
   the gateway **zero times**. Then reply here so we re-verify live.

**`POST /api/v1/checkout/{group}/pay` response — the shape the storefront binds to:**
```json
{ "success": true,
  "data": {
    "payment_id": "9f1c…",        // uuid
    "paid": false,                 // TRUE only on the points-only path
    "status": "pending",           // "paid" when paid is true
    "iframe_token": "tok_…",       // NULL when paid — nothing to open
    "amount": "95.00",             // what the CARD is charged, decimal string
    "discount": "5.00",            // what points covered
    "points_spent": 100,           // a count, not money
    "currency": "TRY"
  } }
```
**Branch on `paid`, not on a null `iframe_token`** — a gateway failure can also
return null. When `paid` is true: skip the iFrame, go straight to the result page.

Delete this section once the sandbox run passes.

### `BUILD_LOYALTY_EARN_PREVIEW.md` — **DONE** (2026-08-17)

`GET /api/v1/loyalty/earn-preview?amount=129.90` → `{ enabled, points, currency }`,
public, no auth. Same `floor(TL × rate)` the sweep uses, so the card cannot promise
a point the ledger will not credit. A comma decimal is accepted; a non-numeric or
negative amount is 422; `loyalty.enabled=false` answers `{enabled:false, points:0}`.
The `ProductCampaigns` card should light up on its own.

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
