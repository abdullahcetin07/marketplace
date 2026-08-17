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
3. **🐛 BUG — FIXED (2026-08-17, commit below). Please re-verify live.**
   The exception was none of the three suspects: PayTR answered
   **`"merchant_oid ile basarili odeme bulunamadi"`** — it did not reject a zero
   amount and nothing was null. It had simply **never seen the order**, because a
   points-only payment never went through the PSP. (The fraction branch was fine:
   a full refund is 1.0 outright, so nothing divided by a zero card charge.)

   **Fix:** both refund actions skip the gateway when `amount_minor === 0`. The
   guard is the card AMOUNT, not `provider_reference === 'points'` — a zero charge
   is the fact, a label is something this module writes and could rename. A partly
   points-funded basket still goes to PayTR, because there is real money in it.

   **A second bug came out with it**, and it is the reason to re-verify carefully:
   `$fully = $refundedTotal >= $payment->amount_minor` compares against the CARD
   charge, which is zero here — so the first *partial* refund of a points-only
   order called itself full and would have re-credited **every** point. Now
   compared against `amount_minor + discount_minor`, the basket the customer
   actually settled. The 500 had been hiding it; skipping the gateway without this
   would have turned a loud failure into a quiet overpayment.

   **Tests:** refunding an `amount_minor = 0` order re-credits the points and calls
   the gateway **zero** times; a basket with card money still calls it once. The
   first test was confirmed to reproduce the production exception verbatim when the
   guard is removed.

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
