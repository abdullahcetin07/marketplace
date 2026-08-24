# Security

---

## CSRF

On for every web route (`bootstrap/app.php`). The Filament panels rely on it.

Exempt: `webhooks/*` only — external callers cannot hold a token. Any webhook
added there must authenticate by **signature verification**, not by being
exempt. An exempt unauthenticated endpoint is an open door.

Sanctum's stateful (SPA) requests still validate the CSRF token; that is the
whole point of SPA mode over bearer tokens.

---

## Session cookies

| Setting | Value | Why |
|---|---|---|
| `encrypt` | `true` | A leaked Redis snapshot otherwise yields replayable sessions |
| `http_only` | `true` | The frontend never reads the cookie; XSS should not either |
| `secure` | `true` outside local | Explicit, so a misconfigured proxy cannot downgrade it |
| `same_site` | `lax` | Allows OAuth/payment return navigations; blocks cross-site POSTs |

`strict` would break payment provider callbacks, which is why `lax` is chosen
rather than defaulted to.

---

## Rate limiting

Named limiters in `AppServiceProvider`, values in
`config/marketplace.security.rate_limits`:

| Limiter | Default | Keyed on |
|---|---|---|
| `api` | 60/min | user id, else IP |
| `auth` | 5/min | **email AND IP, separately** |
| `search` | 30/min | user id, else IP |
| `panel` | 120/min | user id, else IP |

The `auth` limiter returns two limits deliberately. Keying on email alone lets
an attacker rotate IPs against one account; keying on IP alone lets them rotate
accounts from one IP. Both are needed.

Limiters key on user id when authenticated so one user behind a shared NAT
cannot exhaust everyone else's budget.

---

## Authentication and authorization

Covered in depth: [authentication.md](authentication.md),
[authorization.md](authorization.md).

The properties that matter here:

- Three guards over separate models with global scopes — a seller session
  **cannot** resolve as an admin, enforced at the query level rather than by a
  middleware check.
- Permissions are guard-scoped; the same name on two guards is two rows.
- Wildcard permissions are **disabled** — `store.*` would silently grant
  `store.force_delete` the day someone adds it.
- `BasePolicy::owns()` defaults to `false`: a seller-facing policy that forgets
  to implement ownership denies everything rather than granting everything.
- `forceDelete` requires admin regardless of permission.

---

## Passwords

`App\Shared\Rules\StrongPassword` — **three tiers, one per blast radius**:

| Actor | Rule | What a compromise costs |
|---|---|---|
| Admin (`staff()`) | 14 chars, mixed case, digits, symbols | the platform |
| Seller (`seller()`) | 12 chars, mixed case, digits | a merchant's catalogue, prices, payout details |
| Customer (`customer()`) | 8 chars, a letter and a digit | one person's order history |

All three check Have I Been Pwned via k-anonymity, and that is the check doing
the work: a breached password is compromised at any length, while composition
rules mostly move where the sticky note goes.

**The customer tier was relaxed on 2026-08-24** (from the 12 + mixed-case rule
sellers still use) because it sat on a shopper's signup and password-reset form,
where the abandoned reset is itself a security outcome — the customer who gives
up keeps whatever they had. Seller and admin were deliberately left alone; the
split is the point of the change, not a side effect of it.

Registered as the framework default (`Password::defaults()`), so any rule using
`Password::defaults()` gets the policy without opting in. **That default is the
SELLER tier, not the customer one** — an unknown actor type errs strict, which
is what it did before the relaxation and would silently have stopped doing if
the default had been left pointing at `customer()`.

Hashing: bcrypt, 12 rounds (4 in tests).

---

## Two-factor

Columns, casts and `hasTwoFactorEnabled()` ship in Sprint 0; the enrolment flow
does not. `marketplace.security.two_factor.enforced_for` is the rollout switch.

Secrets and recovery codes use the `encrypted` cast — they are never readable
from a database dump alone.

---

## Identifier exposure

Sequential ids never leave the application. Public identifiers are UUIDs,
enforced in three places: `HasUuid::getRouteKeyName()`, `BaseResource::publicId()`,
and the `/api/v1/me` endpoint.

Sequential ids leak business volume — register, place one order, read off the
platform's order count — and make enumeration trivial.

---

## Secrets

- `.env` is gitignored; `.env.example` carries no values.
- `.env.testing` is committed on purpose: no secrets, and it guarantees every
  developer and CI runner uses identical test configuration.
- `APP_KEY` is validated at container start (`docker/entrypoint.sh`) — the
  container refuses to boot without it rather than starting in an unencryptable
  state.
- Sanctum tokens carry the `mos_` prefix so GitHub/GitLab secret scanning can
  recognise a leak.
- `services.sentry.send_default_pii` is `false`.

---

## Logging hygiene

- Failed logins log the attempted **email**, never the attempted password.
- `User::$hidden` covers `password`, `remember_token` and both 2FA columns.
- The activity log records only `name, email, status, type, locale, country` —
  credentials are excluded at the model level.
- Horizon is behind the `system.horizon.view` permission, not merely `auth`:
  job payloads routinely contain customer data, and an Editor with panel access
  has no business reading them.
- Every authorisation denial is logged with user, guard and ability — the
  earliest available signal of both a misconfigured role and a probing attacker.

---

## Transport and headers

- `URL::forceScheme('https')` in production and staging. Without it, a signed
  URL generated behind a TLS-terminating proxy is signed as `http://` and fails
  verification.
- `TrustProxies` configured — otherwise every client IP is the load balancer's,
  which silently breaks all IP-based rate limiting.
- nginx sets `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `X-Permitted-Cross-Domain-Policies`; `server_tokens off`; dotfiles denied;
  non-front-controller `.php` returns 404.
- HSTS is set at the load balancer, not in the container — setting it on a
  plain-HTTP local container would poison the developer's browser.

---

## CORS

`allowed_origins` is an explicit list, never `*`. `supports_credentials` is
`true` (required for Sanctum SPA mode), and browsers reject `*` with credentials
anyway — a wildcard here breaks login rather than loosening it.

---

## Container

- `prod` target runs as `www-data`, never root.
- Xdebug exists only in the `dev` target.
- `expose_php = Off`, `display_errors = Off`.
- The application never writes user content to local disk; everything goes to
  S3, with private documents on a **separate bucket** so a policy
  misconfiguration on the public bucket cannot expose identity documents.

---

## Known gaps for later sprints

| Gap | Sprint |
|---|---|
| 2FA enrolment and challenge flow | Auth module |
| Webhook signature verification | First integration |
| Content Security Policy for panels | Hardening pass |
| Per-plan API rate limits | Commercial tiers |
| Automated dependency scanning in CI | Hardening pass |
