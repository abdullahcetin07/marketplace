# Fix the last 7 failing tests → get the suite to 0 failed

**This is a disposable work order. Delete it once the suite is green.** It is written
for the Claude Code session running ON THE SERVER (which has `vendor/` and can run the
suite). Do all of it, verify with `php artisan test`, commit each fix separately, push.

## Working rules (do not violate)
- Execution-driven: after each fix, run the relevant test and confirm it passes.
- Frozen modules (Identity, Organization, Store): only bug/security fixes — these all are.
- **NEVER edit `tests/Feature/Auth/GuardIsolationTest.php`** to make anything pass.
- When code and a test disagree, fix whichever the design says is wrong (below tells you which).
- Keep commits small: one root cause per commit.

## Current state
7 failing / 351 passing. The 7 are itemised below with exact file, line, and change.

---

## Step 0 — git hygiene first
There is an uncommitted, legitimate fix in `app/Models/User.php` (the `guardName()`
class-actor-type fallback) plus an accidental junk file. Commit the real change, drop
the junk, and do NOT commit local-only files (`.env.testing`, the `.gitignore` churn,
`composer.lock`, the `storage/**` cache files):

```bash
git add app/Models/User.php
git commit -m "fix: fall back to class actor type in guardName for model-less permission scopes"
rm -f -- "udo -u postgres psql"
git push origin main
```

---

## The 7 fixes

### 1. StoreLimitResolutionTest › "reports remaining slots, and null when unlimited"
**Cause:** `Organization::currentStoreCount()` queries the DB on an unsaved model, but
this is a UNIT test (no database). An unsaved org has zero stores by definition.
**File:** `app/Modules/Organization/Domain/Models/Organization.php`, method
`currentStoreCount()` (~line 231).
**Change:** guard before the query:
```php
public function currentStoreCount(): int
{
    if (! $this->exists) {
        return 0;
    }

    return $this->storeOpeningRequests()->approved()->count();
}
```

### 2. ConventionsTest › "preset → security"
**Cause:** the security preset flags `md5` (TimezoneRepository:64 — a cache key) and
`sha1(email)` (VerifyEmailAction, AuthService, VerifyEmailNotification — Laravel's own
email-verification hash convention, where the signed URL is the real credential). All
are non-security uses.
**File:** `tests/Architecture/ConventionsTest.php` (~line 89).
**Change:**
```php
arch()->preset()->security()->ignoring([
    'App\Modules\Localization\Infrastructure\Repositories\TimezoneRepository',
    'App\Modules\Identity\Application\Actions\VerifyEmailAction',
    'App\Modules\Identity\Application\Services\AuthService',
    'App\Modules\Identity\Infrastructure\Notifications\VerifyEmailNotification',
]);
```
If the preset still flags another class, add it to the list (same rationale). Verify by
running only this test and reading which class it names.

### 3. ConventionsTest › "preset → laravel → ignoring 'App\Modules'"
**Cause:** the laravel preset assumes the default skeleton. It flags Core `BaseController`
(has the `Controller` suffix but lives in `App\Core\Presentation\Controllers`, not
`App\Http\Controllers`) and the Filament `AdminPanelProvider`/`SellerPanelProvider`
(in `App\Providers\Filament`, suffix `Provider` not `ServiceProvider`). Both are
deliberate (modular monolith + Filament convention).
**File:** `tests/Architecture/ConventionsTest.php` (~line 87).
**Change:** widen the ignore list:
```php
arch()->preset()->laravel()->ignoring([
    'App\Modules',
    'App\Core\Presentation\Controllers',
    'App\Providers\Filament',
]);
```
If it still flags something, read the class it names and decide: a real violation gets
fixed in code; a deliberate house pattern gets added to `ignoring`.

### 4. KycTest › "stores an uploaded document on the private disk as pending"
**Cause:** the test uploads an EMPTY fake file (`size: 0, mime: application/x-empty`),
which Spatie medialibrary rejects (`FileUnacceptableForCollection`). The fixture is
wrong, not the code.
**File:** `tests/Modules/Organization/Feature/KycTest.php` — find where it builds the
uploaded file (likely `UploadedFile::fake()->create('tax.pdf')` with no size).
**Change:** give the fake a real size and an explicit pdf mime, e.g.:
```php
UploadedFile::fake()->create('tax.pdf', 120, 'application/pdf')
```
(120 = KB.) Match whatever the document collection's accepted mimes are; if it accepts
images too, `->image('doc.jpg')` also works. The point: a non-empty file with an
accepted mime.

### 5. PasswordResetTest › "will not redeem a token across actor types"  ⚠️ REAL SECURITY BUG
**Cause:** all three password brokers (`admins`, `sellers`, `customers`) share the single
`password_reset_tokens` table, keyed by email only (config/auth.php ~line 119). Because
uniqueness is `(type, email)`, the SAME email can be both a customer and an admin. A
token created by the `customers` broker is found by the `admins` broker (same table,
same email) and validates — so a customer's reset token opens the admin account. The
test posts a customer token with `type: admin` and expects `422 RESET_TOKEN_INVALID`;
it currently gets `200`.
**The config comment at ~line 119 CLAIMS separate brokers prevent this — but they don't,
because the table is shared. The intended isolation was never actually implemented.**
**Fix (choose the cleaner one, then verify the test goes 422):**
- **Preferred — per-broker token tables.** Give each broker its own table in
  `config/auth.php` (`admin_password_reset_tokens`, `seller_password_reset_tokens`,
  keep `password_reset_tokens` for customers) and add migrations creating those two
  tables (same schema as the default password reset tokens table: `email` primary,
  `token`, `created_at`). Now a customer token simply does not exist in the admins
  table → invalid → 422.
- Alternative — a custom token repository / broker that scopes the lookup by provider
  so a token issued under one provider cannot validate under another.
Do NOT weaken the test. This must genuinely reject the cross-type token. After the fix,
re-run the whole PasswordResetTest file to be sure no other reset test regressed.

### 6. LoginTest › "stamps last login and increments the counter"
**Cause:** after one login, `login_count` is 2, expected 1 — it increments twice. The
only increment site is `app/Models/User.php:592` (`login_count + 1`, inside the
login-recording method). It is being invoked twice — most likely BOTH the login
action/service AND a `UserLoggedIn` listener call it.
**How to pinpoint:** run
`php artisan test tests/Modules/Identity/Feature/LoginTest.php --filter="stamps last login"`
and trace who calls the recordLogin method (grep the method name; check `AuthService`/
`LoginAction` and any `UserLoggedIn` listener). Increment in EXACTLY ONE place — keep it
in the login action/service and remove the duplicate (or vice-versa), whichever the
design intends. Confirm the counter lands on 1.

### 7. AuditTrailTest › "ties entries to the request correlation id"
**Cause:** `App\Core\Domain\Context\AuditContext::current()` memoizes `$current`
statically (`return self::$current ??= self::system();`). Static state survives between
tests in the same process, so the correlation id captured on the very first model write
in the suite (usually null) sticks forever; the test's later `app()->instance(
'correlation_id', ...)` is ignored, and the audit entry's `correlation_id` is null.
**File:** `tests/TestCase.php`, `setUp()`.
**Change:** reset the holder each test so the first audit read re-evaluates `system()`
against current app state:
```php
protected function setUp(): void
{
    parent::setUp();

    \App\Core\Domain\Context\AuditContext::forget();

    Http::preventStrayRequests();
}
```
(`AuditContext::forget()` already exists.) Verify the test now sees the bound correlation id.

---

## Finish
1. `php artisan test` → confirm **0 failed**.
2. Make sure each fix is its own commit; push everything: `git push origin main`.
3. Delete this file: `git rm FIX_REMAINING_7.md && git commit -m "chore: remove finished work order" && git push`.
4. Report the final `Tests:` line.
