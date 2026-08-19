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

### `BUILD_SECURITY_FIXES.md` — PRE-LAUNCH security fixes (do first) 🔒

A pre-launch security audit (2026-08-18) cleared the platform on almost everything —
PayTR callback hash auth, amount tampering, IDOR surface, guard isolation, mass
assignment, rate limiting, secrets, injection, CORS/session config are all SOUND. It
found **four backend issues; three gate launch** (money integrity / money redirection):

1. **HIGH-ish** — Loyalty points **mis-refunded across two partial returns** of one
   order (reversal key `"{group}:{cause}"` collides → 2nd reversal dropped → customer
   shorted points). Fix = incremental delta keyed per `PaymentRefund` uuid.
2. **MEDIUM** — Points **double-spend race**: `hold()` reads the balance without
   `lockForUpdate` → two concurrent checkouts for one customer over-hold. Fix = lock the
   ledger rows like `CreatePayoutAction` already does.
3. **MEDIUM** — A seller-org **Manager** (has `MemberUpdateRole`, not `BankAccountUpdate`)
   can self-promote to **Finance** and change the **payout IBAN** → money redirection.
   Fix = subset-of-own-capabilities rule + no self-role-change + IBAN-change re-verify.
4. **LOW** — `pay` runs side effects before the ownership check.

Full detail (files, line numbers, exploit, fix, tests): **`BUILD_SECURITY_FIXES.md`**.
The storefront XSS finding (JSON-LD `</script>` breakout) is **already fixed** (commit
`60887c2`). Reply here when done; the desktop session re-verifies #1 live on the sandbox.

**OWNER `.env` checklist (production server — verify before go-live):**
- `APP_DEBUG=false`, `APP_ENV=production` (the `.env.example` template ships `true`).
- `DEBUGBAR_ENABLED=false`.
- `SESSION_SECURE_COOKIE=true` (auto when `APP_ENV=production` — confirm it really is).
- `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `CORS_ALLOWED_ORIGINS` = real prod
  hostnames (templates are `localhost`).
- Real PayTR/DB/mail credentials only in the server `.env`, never committed.

---

### `BUILD_SEO_BACKEND.md` — SEO backend items (post-launch, NOT launch-blocking)

From the pre-launch SEO audit. The **storefront** SEO fixes are done + deployed
(product/store/category/brand JSON-LD + metadata, `shippingDetails`, store canonical,
`Organization` type + breadcrumb, sitemap lastmod). What's left needs the backend or a
content decision — do when convenient:
1. **Small:** a public `GET /api/v1/stores` (live-store slugs, ideally `updated_at`) so
   the sitemap can include `/magaza/*` pages (currently omitted).
2. **Biggest SEO lever, a data decision:** `Product.description` is **empty across the
   catalogue** → thin product pages, no meta substance, nothing for AI to cite. Populate
   from GTIN/brand feed at import, or require it on authoring.
3. Optional: expose `updated_at` on slug/list reads (real sitemap `lastmod`); seller
   `aggregateRating` on `store/{slug}`; short editable description fields for
   category/brand/store hubs.

Full detail: **`BUILD_SEO_BACKEND.md`**. The launch-blocking SEO items are **owner/infra**
(staging `noindex` + DNS cutover so `raftabul.com` serves this app not the WordPress
placeholder; Cloudflare AI-bot policy) — not code.

---

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
3. **🐛 BUG — FIXED (2026-08-17, commit `99133cd`), and RE-VERIFIED LIVE.**
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

   **→ RE-VERIFIED LIVE (2026-08-17):** the points-only refund re-credited **+100**
   (balance 0 → 100). ✅

4. **🐛 SECOND REFUND BUG — FIXED (2026-08-17). Please re-verify live.**
   The exception was **`"iade yapilamiyor, daha sonra tekrar deneyin"`** (err_no
   000, HTTP 200) — and the log line beside it names the cause:
   `payment_amount: "5.00"`. **We asked PayTR for the GOODS value, not the card's
   share.** The ₺10 basket was settled with ₺5 of card and ₺5 of points, so
   refunding one ₺5 unit owes the card **₺2.50** and the customer **50 points** —
   but the full ₺5.00 was sent, against a ₺5.00 charge. PayTR refused it. Had it
   agreed, the buyer would have been handed back more than they paid.

   Not the proportional points maths (that was already right, floored), not the
   S4 hash bug, and no type/null.

   **Fix:** the gateway leg is now scaled by the card's share of the settled basket
   — `floor(amount × card / (card + points))` — in both refund actions. Only that
   leg is scaled: the recorded refund, the seller-ledger reversal and the restock
   stay on the full goods value, because the seller was paid in full and the
   platform funded the discount. The share is floored, so a rounding remainder
   stays with the platform rather than over-refunding. A share that rounds to zero
   skips the PSP entirely, which also subsumes the points-only case from #3.

   **Tests:** a partial refund of a mixed basket re-credits the proportional
   floored points (50, not 100) and asks the PSP for the CARD share; confirmed to
   fail with `3000 !== 1500` when the scaling is removed.

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
