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

### `BUILD_LOYALTY_P2.md` — Loyalty redemption at checkout (ADR-084)

Phase 1 (earning + ledger + admin + read API) is **BUILT and live**. Phase 2 adds
**spending** points as a **platform-funded checkout discount**.

- A Core command port **`LoyaltyContract`** (`hold → commit → release` + `reverse`) —
  Payment/Order call it; **nobody imports Loyalty and Loyalty imports nobody**
  (`LayeringTest`). `LoyaltyPointSource` gains `Redemption` + `Reversal`. A hold is
  transient (not a ledger row); only `commit` writes `−points`.
- **Quote endpoint** `POST /api/v1/loyalty/redeem/quote` — pure preview (discount +
  payable) over the caller's cart; no state.
- **Apply at pay**: `POST /api/v1/checkout/{group}/pay` accepts `{ points }` → Payment
  holds, charges PayTR **total − discount**; the **platform absorbs it** (no
  seller-order/commission/KDV change).
- **Callback** commits (success) / releases (fail+expiry). **`PaymentRefunded`**
  re-credits the spent points (proportional on a partial refund).
- **Edge that MUST be handled — `payable == 0`** (no cap, so reachable): no PayTR
  charge; mark paid-via-points and run the same success path. Do not send a 0-amount
  order to PayTR.
- Confirm the P1 purchase sweep earns on the **really-paid** TL (total − discount), not
  the pre-discount total.

Full detail: **`BUILD_LOYALTY_P2.md`**. Spec: `docs/modules/Loyalty.md` §5. Decision:
**ADR-084** + amendment log #19. The storefront `/odeme` "Puanını kullan" control is
already built against the quote + `pay {points}` contract and stays hidden until this
ships. Delete this section once P2 lands and is verified on PayTR sandbox (including
the `payable == 0` path and a refund re-credit).

**Owner clarifications (2026-08-15, in answer to your two pre-start questions):**
1. **No cap — confirmed.** A customer may pay 100% of the cart with points; the
   platform absorbs it and the seller is paid in full (ADR-084 unchanged, no
   amendment, no `max_percent` setting). Build the `payable == 0` path as specified.
2. **PayTR: use the SANDBOX** already configured on this server (the same one Payment
   went live against). Read your own `.env` to confirm the sandbox merchant creds are
   present and report what you find; do **not** run the verification against live PayTR
   / real charges. If sandbox creds are somehow missing, stop and say so rather than
   falling back to live.

Proceed with your stated order: Core port → hold/commit/release → quote → pay
integration → callback → refund → tests → ADR/doc, `make check` each phase.

### Also queued (small, independent of P2): `BUILD_LOYALTY_EARN_PREVIEW.md`

A tiny public read `GET /api/v1/loyalty/earn-preview?amount=<decimal>` →
`{ enabled, points, currency }`, `points = floor(amount × loyalty.earn.purchase_rate)`
(the SAME computation as the purchase sweep). Public (no auth), reads only `settings()`,
no Core contract, imports nothing. The storefront product-page "Kampanyalar" card ("Bu
ürünü alınca X puan kazan") is already built against it and stays hidden until it ships.
Do it whenever convenient — it's not on the P2 critical path.

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
