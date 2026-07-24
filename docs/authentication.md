# Authentication

Three independent guards — `admin`, `seller`, `customer` — over one `users`
table.

---

## The design

```
users (one table)
  └── type: 'admin' | 'seller' | 'customer'   ← discriminator

App\Models\Admin     global scope: where type = 'admin'      → guard 'admin'
App\Models\Seller    global scope: where type = 'seller'     → guard 'seller'
App\Models\Customer  global scope: where type = 'customer'   → guard 'customer'
App\Models\User      no scope — the unscoped base, for relations
```

`config/auth.php` gives each guard its own **user provider**, and each provider
resolves a **different model**:

```php
'guards' => [
    'admin'    => ['driver' => 'session', 'provider' => 'admins'],
    'seller'   => ['driver' => 'session', 'provider' => 'sellers'],
    'customer' => ['driver' => 'session', 'provider' => 'customers'],
    'sanctum'  => ['driver' => 'sanctum', 'provider' => 'customers'],
],
```

Guard names are `UserType` values, verbatim. `UserType::Admin->guard()` returns
`'admin'`. This is not a coincidence to be tidied up later — the same string is
the guard name, the `users.type` value, and the Spatie `guard_name`. Keeping one
source of truth is what stops the three from silently drifting apart.

---

## Why the isolation actually holds

The important property is that **a seller session can never authenticate as an
admin**. That is not enforced by a middleware check somebody could forget to
write. It is enforced at the query level:

1. The `admin` guard uses the `admins` provider.
2. The `admins` provider resolves `App\Models\Admin`.
3. `Admin` applies a global scope of `where type = 'admin'`.
4. A seller's row has `type = 'seller'`, so the provider's query does not
   return it — the user simply does not exist as far as that guard is concerned.

An attacker holding a valid seller session cookie, or even a valid admin
password on a seller account, gets nothing. There is no code path where a
forgotten `if` opens the door.

`tests/Feature/Auth/GuardIsolationTest.php` asserts every part of this. If any
test in that file fails, treat it as a privilege-escalation bug, not a test
failure.

---

## Why one table rather than three

Three tables (`admins`, `sellers`, `customers`) is the other common answer.
Rejected because:

- **Identity is genuinely shared.** Credentials, email verification, 2FA,
  password resets, sessions, locale, soft deletes — all identical across the
  three. Three tables means three copies of every one of those flows.
- **One human can be two actors.** A seller who also shops on the platform is
  normal. With three tables that is either forbidden or duplicated.
- **Password reset is one flow**, not three near-identical ones with subtly
  divergent token handling.

**The cost, stated plainly:** `users` carries nullable columns that only some
types use. That is real, and it is the price. It is documented rather than
hidden, and it is far cheaper than triplicating the auth surface.

---

## User name structure

`first_name` is required; `last_name` is **nullable** (ADR-012).

Nullable is not a tolerated edge case — it is the reason for the decision. The
platform serves sole traders and markets where a single given name is normal,
and requiring a surname would make those accounts unrepresentable.

The display name is **computed**:

```php
$user->display_name;   // trim(first_name . ' ' . last_name)
$user->initials();     // "AY", or "M" with no surname
```

**There is no `full_name` column and there must never be one.** A denormalised
copy is a second source of truth that drifts the first time one side is written
alone. `display_name` is an Eloquent accessor, so it works unchanged in Blade,
Filament table columns and API resources.

The API emits all three — `first_name`, `last_name`, `display_name` — so a
client never has to reimplement the join rule.

---

## Email uniqueness

Unique on **`(type, email)`**, not on `email` alone.

- The same address may exist once as a seller and once as a customer.
- A global unique index would also be an oracle: anyone could probe whether a
  given address belongs to an admin.

On PostgreSQL a partial unique index (`WHERE deleted_at IS NULL`) additionally
allows a deleted account's address to be reused. SQLite (the test connection)
relies on the composite unique alone, which is sufficient there.

---

## Filament panels

| Panel | Path | Guard | Registration | Colour |
|---|---|---|---|---|
| Admin | `/admin` | `admin` | **disabled** | Rose |
| Seller | `/seller` | `seller` | enabled | Amber |

Registration is enabled for sellers and disabled for admins deliberately:
sellers onboard themselves and are then approved; a self-service admin signup
would be a critical vulnerability.

The colours differ on purpose. An operator who holds both accounts should be
able to tell at a glance which context they are acting in.

Customers have **no panel**. `UserType::Customer->panel()` returns `null`, which
is what makes a customer session unable to satisfy any panel route. They are
served entirely by the Next.js storefront over the Sanctum-protected API.

Both panels also call `User::canAccessPanelId()` through Filament's
`canAccessPanel()` hook — a second, independent check that also rejects
suspended and soft-deleted accounts.

---

## API authentication

The Next.js storefront uses **Sanctum SPA mode**: session cookie plus CSRF
token, for origins listed in `config/sanctum.php`. Not bearer tokens — a token
in browser storage is readable by any XSS, whereas an httpOnly cookie is not.

- `sanctum.stateful` must list the storefront origin.
- `cors.supports_credentials` must stay `true`, or the cookie is never sent.
- `cors.allowed_origins` is an explicit list, never `*`.

Tokens expire after 7 days (`sanctum.expiration`) and are pruned nightly. A
token's abilities mirror the issuing user's permissions, so a token can never
grant more than the session that created it.

---

## The login flow

`AuthService` is the only entry point. Controllers, Filament and the console all
go through it, because every path into authentication must produce the same side
effects — an attempt row, a session row, a device row, a domain event — and a
second entry point is a second place to forget one.

`LoginAction` does the work. Three things it gets right that are easy to get
wrong:

1. **No account enumeration.** Missing account, wrong password and suspended
   account return an identical body and status. The real reason is recorded on
   the attempt row; the client never sees it. Only `unverified` and the 2FA
   reasons disclose themselves, and only *after* the password is proven correct.
2. **No timing oracle.** When the address does not exist, a bcrypt comparison
   still runs against a dummy hash. Without it, "no such user" returns in ~1 ms
   and "wrong password" in ~100 ms — a reliable enumeration channel regardless
   of what the body says.
3. **Session fixation is closed.** `session()->regenerate()` fires on privilege
   change.

`LoginAction` runs **outside a transaction**, deliberately: the attempt row must
survive the exception that unwinds a failed login. Rolling it back would erase
exactly the evidence the table exists to keep.

---

## Sessions and devices

Two tables, because a user signs in from the same laptop fifty times — that is
one device and fifty sessions.

| Table | Row per | Purpose |
|---|---|---|
| `user_devices` | browser/app installation | "trust this device", recognisable labels |
| `user_sessions` | sign-in | the security page: where am I logged in |

`user_sessions` is a **projection**, not the authentication mechanism. Marking a
row revoked while the cookie still works produces a UI that lies — so
`SessionService::revoke()` also deletes the framework session row and the
Sanctum token, in one transaction. That is the single most important property in
this module.

`fingerprint` is an HMAC of the user agent and accepted languages, **keyed with
the user id and the app key**. The same browser fingerprints differently for
different accounts, so it cannot correlate one person across them. Deliberately
coarse — including the IP would invalidate the device on every network change.

Device trust is time-limited (`two_factor.trust_days`, default 30). Indefinite
trust is a permanent 2FA bypass on hardware the user may no longer own.

```
GET    /api/v1/sessions              list
DELETE /api/v1/sessions/{uuid}       revoke one
DELETE /api/v1/sessions              revoke all others
```

Bound by UUID. `is_current` is emitted so the UI never offers a revoke button
that logs the user out of the tab they are looking at. IPs are masked to /24.

---

## Login history

`login_attempts` records every attempt, successful or not, with a nullable
`user_id` so attempts against non-existent addresses are kept — that is exactly
what enumeration looks like.

Never stores the attempted password. See [audit.md](audit.md).

---

## Password changes

`ChangePasswordAction` cascades: **every other session is revoked**, and
`remember_token` is cleared so a "remember me" cookie issued under the old
password stops working.

The session performing the change is kept alive on purpose — logging the user
out of the tab they just used is hostile and self-defeating.

A no-op change (same password) is refused before anything is touched, so it
cannot cascade sessions for no benefit.

---

## Two-factor authentication

**Infrastructure complete; no UI.** `TwoFactorService` implements enrolment,
verification, recovery codes and disabling. Every method is callable and
tested. What Sprint 1 does not ship is a Filament page or an API endpoint that
drives enrolment.

```php
$secret = $twoFactor->generateSecret($user);   // persisted UNCONFIRMED
$uri    = $twoFactor->provisioningUri($user);  // otpauth:// for the QR code
$codes  = $twoFactor->confirm($user, $code);   // returns recovery codes ONCE
```

Enrolment is two-step on purpose: the account is not protected until `confirm()`
proves the authenticator works. Enabling on the strength of a generated secret
alone would lock out any user whose QR scan failed.

**Recovery codes are hashed.** They are credentials — a database leak handing
over plaintext recovery codes is a 2FA bypass for every enrolled user. They are
readable exactly once, at generation.

TOTP is verified via `pragmarx/google2fa` rather than hand-rolled: verification
looks easy and fails silently, and a wrong time-window comparison accepts codes
it should reject.

`marketplace.security.two_factor.enabled` plus `enforced_for` is the rollout
switch. When a user must enrol but has not, login fails with
`two_factor_enrolment_required` so the caller can route them into enrolment
rather than simply refusing them.

---

## Password policy

`App\Shared\Rules\StrongPassword`:

| Actor | Minimum | Requirements |
|---|---|---|
| Admin | 14 chars | mixed case, digits, symbols, not breached |
| Seller / Customer | 12 chars | mixed case, digits, not breached |

The asymmetry is intentional. An admin compromise is a platform-wide incident;
a needlessly hostile customer signup form costs real revenue. Both tiers check
Have I Been Pwned, which catches far more real-world compromise than any
composition rule.

`Http::preventStrayRequests()` in `TestCase` stops that check from calling out
during tests.

---

## The first administrator

Created interactively, never seeded:

```bash
make admin
```

A seeded admin means every environment ships with an account whose password is
in version control. `CreateAdminCommand` prompts for the password rather than
accepting it as an argument, where it would land in shell history and process
listings.
