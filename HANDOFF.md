# Integration Handoff — test suite green-up

**This is a disposable working note.** Delete it once the suite is green. It exists
so a Claude Code session running ON THE SERVER (where `vendor/` and a real PHP 8.3
runtime exist) can continue the first real test-suite integration without a human
relaying output. You have the tools to run the suite yourself — do so.

---

## How to work (you are autonomous here)

- You are in the repo at `/var/www/www.raftabul.com/test`, bare-metal PHP 8.3,
  PostgreSQL/Redis available, **no Docker**. The Pest suite runs against sqlite
  `:memory:` (phpunit.xml) — it does NOT touch the app's Postgres, so schema works
  on both paths.
- **Run the suite yourself**: `php artisan test`. Group failures, get the REAL
  exception, fix the root cause, re-run. No git round-trip is needed anymore —
  edits and tests are the same filesystem. Commit each fix (small, one root cause
  per commit) and push so history stays clean.
- To see the real exception behind a 500 (Pest hides it in the compact view):
  `php artisan test --filter="<part of test name>" 2>&1 | tail -50`, or
  `grep -iE "Exception:|Attempted to lazy load|not retrieved" storage/logs/laravel.log`.
- Cluster fast: `php artisan test 2>&1 | tee /tmp/tr.txt | tail -6`, then
  `grep "⨯" /tmp/tr.txt` and
  `grep -iE "[A-Za-z]+Exception: |not retrieved|no such table|Attempted to lazy load" /tmp/tr.txt | sed 's/^ *//' | sort | uniq -c | sort -rn`.

## Binding rules (do not violate)

1. **Execution-driven only.** Fix a failure only after you have SEEN it fail and
   read its real exception/message. No speculative refactors. Do not refactor
   working code.
2. **Read the raw exception, never the normalized count.** One root cause produces
   many "Failed asserting that N is identical to N" lines.
3. **NEVER edit `tests/Feature/Auth/GuardIsolationTest.php` to make it pass** — a
   failure there is a privilege-escalation bug; fix the code (CLAUDE.md).
4. **Frozen modules** (Identity v2.0, Organization v1.0, Store v1.0): only
   bug/security/compat fixes. The fixes below ARE such fixes (missing import,
   wrong route prefix, strict-mode compat) — legitimate. Keep that bar.
5. When code and a test disagree, decide which is authoritative from the design
   (docblocks, ADRs, docs/) — do not just change the test to pass. Example already
   resolved: a wrong 2FA code returns `TWO_FACTOR_INVALID` (it is in
   `AuthenticationFailed::DISCLOSABLE`), so the test expectation was stale, not the
   code.
6. **Strict mode is ON in tests** (`Model::shouldBeStrict(!isProduction())`,
   AppServiceProvider): `preventAccessingMissingAttributes` + `preventLazyLoading`
   throw in tests but only LOG in production. Most 500s here are this class of
   test-only artefact — the production path (guard loads full row / downgrade) is
   fine. Prefer test-setup or explicit-eager-load fixes over weakening the rule.
7. **Host constraint reminder** (does not apply on the server, which HAS vendor):
   the local Windows box that authored the earlier commits has PHP 8.1, no vendor,
   no Docker. That is why this handoff exists.

## Progress

`~300+ failed` → **31 failed / 326 passed** (last fully-verified run, seed 1784976498)
→ two more fixes pushed since (DevicePolicy import + UserResource loadMissing,
commit `c010bdf`) expected to clear ~7 more → **~24 remaining, unverified**. Your
first action: `git pull` (if needed) then run the full suite to get the true count.

## Fixes already applied (all on `main`, pushed)

Earlier batch (commits up to `c299f3b`): Laravel 12 compat + first runtime pass —
`TrustProxies::HEADER_*`→`Request::HEADER_*`; removed the report()-facade bootstrap
crash; `config/app.php` locale as plain strings; scheduler `->name()` ordering;
`AuditEntry::wasChanged`→`attributeWasChanged`; 8 actions `handle(): void`→`: mixed`
`+ return null`; `config/sanctum.php` guard `['admin','seller','customer']`; removed
the global `CheckAbilities` api-prepend (was 401ing every request); factory resolver
`Factory::guessFactoryNamesUsing`; `BaseResource` illegal abstract toArray removed;
4 Organization requests `toDto(?int = null)`; `Gate::after` `Response|bool|null`;
`AuditContext::system()` correlation-id fallback; `BaseNotification::shouldSend`→
`shouldSendType`; two arch-test `->ignoring(...)`. Env: `.env.testing` APP_KEY
regenerated; `php8.3-sqlite3` installed.

Recent batch (commits `8a43fd8`..`c010bdf`):
- `tests/TestCase.php` — `actingAs{Admin,Seller,Customer}` now `refresh()` the model
  (hydrate all columns → no MissingAttributeException) AND
  `loadMissing('roles.permissions','permissions')` (→ no permissions lazy-load).
- `UserFactory::withTwoFactor()` — valid base32 secret + bcrypt-hashed recovery
  codes (were `Str::random`, which broke Google2FA and the strict Bcrypt hasher).
- `routes/api.php` — moved `/two-factor/email-otp` OUT of the `auth/` prefix group
  (it was served at `/auth/two-factor/email-otp`, 404); now public + `throttle:auth`.
- `TwoFactorTest` — expect `TWO_FACTOR_INVALID` on a wrong enrolment code.
- `StrongPassword::for()` — return the relaxed `testing()` rule under
  `runningUnitTests()`, mirroring `default()` (strict tiers call `uncompromised()`
  = an HTTP call that `Http::preventStrayRequests()` blocks). Production unchanged.
- `RegisterRequest` — `email:rfc` (dropped `,dns`), matching every other email rule.
- `IdentityServiceProvider` — added the missing `use ...\Presentation\Policies\DevicePolicy`
  (the bare `DevicePolicy::class` resolved to a non-existent class → 6 device 500s).
- `UserResource` — `loadMissing` before `getAllPermissions()` on the self row.

## Remaining clusters (as of the 31-fail run — will shift after the last push; re-run first)

- **403 ↔ 200 authz** (org + device isolation: "isolates one organization from
  another", "denies a seller viewing an organization", "cannot trust/view/forget
  another user device"). SOME of these were DevicePolicy 500s and should now
  resolve or turn into a clean 403/200 — re-run to see what's genuinely a wrong
  authorization decision vs. a fixed 500. **These touch real authz — fix the code,
  not the test, and never GuardIsolationTest.**
- **StoreLimitResolution** (2): `Tests\Unit\Organization\StoreLimitResolutionTest`
  hits the DB (`no such table: store_opening_requests`) — a UNIT test must not touch
  the DB (CLAUDE.md). Also the query is malformed on an unsaved model
  (`organization_id IS NULL AND organization_id IS NOT NULL`) from
  `Organization::currentStoreCount()`/reportRemainingSlots around
  `Organization.php:203/233`. Decide: make it a Feature test, or guard the query
  when the org is unsaved.
- **arch preset** (2): `preset → laravel → ignoring 'App\Modules'` and
  `preset → security` in `Tests\Architecture\ConventionsTest`. Read what the preset
  actually flags; the modular-monolith layout (ADR-002) legitimately diverges from
  the default-skeleton preset — narrow the preset or `->ignoring(...)` precisely,
  do not blanket-disable.
- **SuspiciousLogin / threat-detection** (~4-5): "grades credential stuffing across
  many IPs as critical", "notifies the account owner and alert-permitted admins",
  "does not notify an owner when the attacked address has no account", "writes a
  high-severity forensic audit entry for brute force", "ties entries to the request
  correlation id" — one `ErrorException` + assertion mismatches; likely one shared
  root cause. Get the ErrorException first.
- **Email verification** (~5): "verifies an email through a valid signed link",
  "fires EmailVerified only on the first verification", "rejects a link whose hash
  does not match", "is idempotent on a repeat click", "stamps last login and
  increments the counter", "will not redeem a token across actor types" — get one
  real response/exception.
- **Misc singles**: "does not let a seller role satisfy an admin permission check";
  "stores an uploaded document on the private disk as pending"; assorted
  `Failed asserting that false is true` / `null is identical to <uuid>` /
  `2 is identical to 1`.

## Method that has been working

Pick the biggest cluster → run one representative in isolation with `--filter` →
read the REAL exception/response body → find the single root cause (often one line)
→ fix → re-run the whole suite → regroup. One shared root cause has repeatedly
cleared 6-14 tests at once. Commit each fix separately and push.
