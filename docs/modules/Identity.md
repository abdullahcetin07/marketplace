# Identity Module Specification
Version: 2.0 — **feature-complete and FROZEN** (Phases 0–8 built; Phase 9
Impersonation deferred, off the critical path)

> ## 🧊 Identity is frozen
>
> The Identity sprint is closed. The module is feature-complete. Only these
> changes are permitted, and each must say which category it falls under:
>
> - **bug fixes**
> - **security fixes**
> - **compatibility updates**
> - **changes explicitly required by a later module** (e.g. Organization)
>
> **No new Identity features.** Phase 9 (Impersonation, Q4) is valuable but not
> on the marketplace critical path; it stays deferred until explicitly revived.
> The next implementation sprint is **Organization**.

Part of the **Foundation module group** (ADR-002).

Governed by: `CLAUDE.md` → `Architecture_Decision_Record.md` →
`001_Architecture.md` → `003_Database_Standards.md` → `002_Coding_Standards.md`
→ `004_Naming_Conventions.md` → `005_API_Standards.md` → this document
(ADR-003).

---

# 1. Purpose

Identity owns **everything around authentication** — sessions, devices, login
history, two-factor enrolment, password lifecycle, and the account self-service
API.

It does **not** own `App\Models\User`. That lives in `app/Models/` because
`app/Core` (`BasePolicy`) and `app/Shared` (`HasCreator`, `HasUpdater`)
reference it; placing it in a module would invert the dependency graph
(`001_Architecture.md` §6).

## 1.1 Identity is the root of the dependency chain

**Identity must remain completely independent of Organization, Store and every
marketplace module.**

```
Identity  →  Organization  →  Store  →  Catalog  →  Offer  →  Order
```

Nothing above Foundation may be referenced from this module. The dependency
runs one way, always.

### The invariant

**A `User` is complete on its own.** It may exist with:

- no Organization
- no Store
- no seller profile

Every Identity flow — registration, login, password reset, email verification,
2FA, session and device management — must work for an account that belongs to
nothing at all.

### What this forbids, concretely

- Never add a non-nullable `organization_id` to `users`.
- Never make an Identity flow conditional on membership.
- Never import `App\Modules\Organization\*` from this module.
- Membership is **Organization's** concern, expressed as a pivot Organization
  owns and queries.

When Organization needs to react to an Identity event, it **subscribes** —
exactly as Activity does today with `RecordIdentityActivity`. Identity stays
ignorant of whether anything is listening.

This is a consolidation of existing rules (`001_Architecture.md` §5, §5.1, §26
and `Foundation.md`), not a new decision — no ADR was raised for it.

---

## 1.2 Scope boundary

| Identity owns | Identity does not own |
|---|---|
| Sessions, devices, login attempts | The `users` table (core migration) |
| Auth flow: login, logout, register | Roles and permissions (Spatie + `PermissionRegistry`) |
| Password reset and change | Locale data (Localization module) |
| Email and phone verification | Audit and activity records (their own modules) |
| 2FA enrolment and challenge | Notification delivery (Notification module) |
| Account self-service API | Organization membership (later sprint) |

---

# 2. Current state

Sprint 1 delivered a partial module. This specification covers **completing**
it, not rebuilding it.

## 2.1 Built and working

| Layer | Artefact |
|---|---|
| Domain models | `UserSession`, `UserDevice`, `LoginAttempt` |
| Domain DTOs | `LoginDTO`, `RegisterUserDTO` |
| Domain events | 9 — `UserCreated/Updated/Deleted`, `UserLoggedIn/Out`, `PasswordChanged`, `TwoFactorEnabled/Disabled`, `SessionRevoked` |
| Domain exceptions | `AuthenticationFailed` — enumeration-safe, 7 reasons |
| Actions | `LoginAction`, `LogoutAction`, `RegisterUserAction`, `ChangePasswordAction` |
| Services | `AuthService`, `SessionService`, `TwoFactorService` |
| Infrastructure | `UserObserver` |
| Presentation | `AuthController`, `SessionController`, `UserPolicy`, `UserSessionPolicy`, `LoginRequest`, `RegisterRequest`, `UserResource`, `SessionResource` |
| Migration | `2026_01_01_000001_create_identity_tables.php` |
| Factories | `UserSessionFactory`, `UserDeviceFactory`, `LoginAttemptFactory` |
| Tests | `LoginTest` — 14 cases on the security-critical paths |

## 2.2 Missing — this specification's work

| Gap | Detail |
|---|---|
| **Repositories** | Zero. `001_Architecture.md` §4 requires them per module |
| **Password reset** | Brokers configured per guard; no action, no endpoint, no notification |
| **Email verification** | `MustVerifyEmail` implemented; no endpoint, no notification |
| **2FA API** | `TwoFactorService` complete; no controller, no requests, no resource |
| **Device management** | Model and trust logic exist; no endpoint |
| **Profile update** | No `UpdateProfileAction`, no endpoint |
| **Impersonation** | `user.impersonate` permission registered; nothing implements it |
| **Notifications** | No `BaseNotification` subclasses at all |
| **Attack detection** | ✅ Phase 7 — `classifyThreat()` wired into the failure path; forensic entry, timeline line, owner + admin alerts (Q6) |
| **Filament** | No admin User resource, no seller profile page |
| **Tests** | 1 suite of an expected 10 |

---

# 3. Blocking prerequisite — Phase 0

**The API response envelope does not match ADR-009.**

| | Code emits today | ADR-009 requires |
|---|---|---|
| Success | `{data, meta}` | `{success, message, data, meta}` |
| Error | `{error: {code, message, context}}` | `{success, code, message, errors}` |

Identity adds roughly 15 endpoints. Building them against the current envelope
ships 15 ADR-009 violations; building them against ADR-009 leaves the platform
with two incompatible envelopes and a frontend that must branch.

**Phase 0 must land before any Identity endpoint.** Scope:

- `BaseController` — `success` and `message` in the envelope
- `BaseException::render()` — the canonical error shape
- `BaseRequest::failedValidation()` — `code: VALIDATION_ERROR`
- Error codes become `UPPER_SNAKE_CASE` (`005` §25)
- `LoginTest` assertions updated (`error.code` → `code`)
- `/api/v1/health` and `LocalizationController` wrapped in Resources (`005` §18)

This is not Identity work, but Identity cannot start without it.

---

# 4. Domain model

## 4.1 Tables

No new tables. Identity's three exist; `users` is core.

| Table | Owner | Notes |
|---|---|---|
| `users` | core migration | `(type, email)` composite unique (ADR-012) |
| `user_sessions` | Identity | Projection for the security page, not the auth mechanism |
| `user_devices` | Identity | One per browser per user; HMAC fingerprint |
| `login_attempts` | Identity | Append-only, nullable `user_id` |
| `password_reset_tokens` | core migration | Shared across three brokers |

**ADR-016 note:** these tables predate the audit-column rule and may migrate
incrementally. No retrofit is scheduled in this phase.

## 4.2 A decision required — `first_name` / `last_name`

ADR-012 mandates `first_name` and `last_name`. The `users` table currently has
a single `name` column.

This is a **core migration change**, touching `UserFactory`, `UserResource`,
`CreateAdminCommand` and Filament's `getFilamentName()`. It belongs in this
phase because every Identity endpoint returns a user.

→ **Open question Q1.**

---

# 5. Business rules

## 5.1 Authentication

1. Three guards — `admin`, `seller`, `customer` — over one `users` table,
   isolated by a per-subclass global scope on `users.type`.
2. **A failed login must be indistinguishable** across: no such account, wrong
   password, suspended account, wrong guard. Only `unverified` and the two 2FA
   reasons disclose themselves, and only after the password is proven.
3. **No timing oracle.** A missing account still runs a bcrypt comparison.
4. **Every attempt is recorded**, success or failure, including attempts
   against addresses that do not exist.
5. The attempted password is never stored, not even hashed.
6. Session id regenerates on login (fixation).
7. Staff accounts (`UserType::isStaff()`) must have a verified email; customers
   need not.

## 5.2 Password lifecycle

1. Changing a password **revokes every other session** and clears
   `remember_token`.
2. The session performing the change stays alive — logging the user out of the
   tab they just used is hostile and self-defeating.
3. A no-op change (same password) is refused before anything is written.
4. Reset tokens: 15 minutes for admins, 60 for sellers and customers.
5. A reset request must not reveal whether the address exists — always the same
   response, always the same timing.
6. Password policy: `StrongPassword::for($type)` — 14 chars plus symbols for
   staff, 12 for everyone else, both checked against Have I Been Pwned.

### Device identification — no user-defined names

The platform does **not** support user-assigned device names. A user identifies
a device from what it is, not from a label they had to invent.

The device list carries the four signals a person recognises a device by:

| Field | Source |
|---|---|
| OS | `platform` |
| Browser | `browser` |
| Approximate location | `location` — coarse, geo-IP-populated (deferred) |
| Last seen | `last_used_at` |

Plus a computed `label` ("Chrome on Windows") and the trust state.

The `name` column was dropped (migration
`2026_02_01_000002_adjust_user_devices_display`); it held a user label for a
feature that is cancelled.

**The fingerprint is an internal implementation detail and is never exposed** —
enforced in `DeviceResource`, asserted in `DeviceTest`.

### Email changes

**Phase 5 profile update deliberately does NOT change the email address.**
`PATCH /profile` touches name, phone and locale only. Email is a credential and
an identity key `(type, email)`; changing it is its own workflow, not a field on
a profile form.

When email change IS built, these invariants hold:

1. **`email_verified_at` becomes null immediately** — a new address is
   unverified by definition, whatever the old one's status.
2. **A verification email is sent to the new address.**
3. **The new address is not trusted until the verification flow completes.**

Notes 1 and 4 (below) describe the same feature at two maturity levels, and
Note 4 wins where they meet:

- **Interim model** — replace the address, null the flag, re-verify. Simple,
  but the account is briefly reachable only at an unverified address.
- **Target model (Note 4, backlog)** — a **pending** address alongside the
  current one. The current address keeps working until the pending one is
  verified, then activation swaps them. **The current email is never replaced
  before the new one is verified.**

Under the target model the invariants above apply to the *pending* address:
it starts unverified, a verification email goes to it, and it is not activated
until that completes.

### Administrator manual verification (backlog)

An administrator may mark an address verified without the email round-trip — for
support cases where a user cannot receive mail. Requirements when built:

- **Audit event required** — this bypasses proof of mailbox control, so it must
  be attributable.
- **Actor required** — which administrator did it.
- **Reason required** — free text, recorded on the audit entry.

Permission: a dedicated `user.verify_email` ability, not folded into
`user.update` — manually asserting mailbox control is a distinct, higher-trust
action.

### EmailVerified as a documented extension point

`EmailVerified` (Domain event) fires once, on first verification. It is the
sanctioned hook for reacting without touching the verification path.

Current subscriber: Activity (`RecordIdentityActivity`).

Anticipated subscribers, each a listener in its own module — never a change to
`VerifyEmailAction`:

- **Welcome automation** — onboarding email sequence
- **Seller onboarding** — a verified email is a precondition of store approval
- **Loyalty** — enrolment on a confirmed identity
- **Marketing integrations** — opt-in sync, gated on a real inbox

The event carries scalars only (`userId`, `userUuid`, `guard`) — no address, no
token.

### Token handling (ADR-025)

7. **Tokens never appear in an API response.** Out-of-band, by email, only.
8. **Reset tokens are single-use** and invalidated immediately on success.
9. **Issuing a new token invalidates every previous unused token** for that
   address — otherwise a user who requests three resets leaves three live
   credentials in three inboxes.
10. **Email verification tokens expire** after the configured lifetime.

### After a successful reset

11. **Every active session for that user is revoked.** The user is not
    authenticated during a reset, so there is no current session to preserve —
    unlike a voluntary change, where the acting session survives.
12. **`remember_token` is cleared**, or a cookie issued under the old password
    keeps working and the revocation has a hole in it.
13. **An audit event is always recorded**, on both the success and the failure
    path.

## 5.3 Sessions and devices

1. `user_sessions` is a **projection**. Revoking must also destroy the
   framework session row and the Sanctum token, in one transaction. A row that
   claims to be revoked while the cookie still works is the worst failure mode
   in this module.
2. One device per (user, fingerprint). Re-signing in updates, never duplicates.
3. Device trust is time-limited (`two_factor.trust_days`, default 30).
   Indefinite trust is a permanent 2FA bypass on hardware the user may no
   longer own.
4. A user may only see and revoke **their own** sessions —
   `UserSessionPolicy::ownershipRequiredFor()` includes `view`, because a
   session list is IP addresses and device fingerprints.

## 5.4 Two-factor

1. Enrolment is two-step: `generateSecret()` persists **unconfirmed**;
   `confirm()` proves the authenticator works before the account is protected.
   Enabling on a generated secret alone locks out any user whose QR scan failed.
2. Recovery codes are **hashed**, shown exactly once, single-use. Their count,
   length and hash algorithm are **configuration**, not constants (ADR-026) —
   `two_factor.recovery_codes.{count,length,hash}`. `hash` names any registered
   Laravel hasher; changing it affects only new codes, since `Hash::check()`
   reads the algorithm from each stored hash's prefix.
2a. The TOTP algorithm sits behind `TotpProviderContract` (ADR-026). Services
   and controllers never name Google2FA; `Google2FaTotpProvider` is the only
   class that does, bound in `IdentityServiceProvider::registerTotp()`. An
   architecture test (`ConventionsTest`) fails the build if that leaks.
2b. The OTP store is a **shared Core primitive**, not Identity's
   (`App\Core\Domain\Contracts\OtpStoreContract`, cache-backed in Core). It
   operates on an opaque identifier with no business meaning, so future modules
   — email-verification fallback, sensitive-action confirmation, store-ownership
   checks, org invitations — reuse it without importing Identity. Bound in
   `AppServiceProvider`, `secret_length` and OTP length are configurable.
3. `two_factor.enforced_for` is the rollout switch. A user who must enrol but
   has not gets `TWO_FACTOR_ENROLMENT_REQUIRED` so the caller can route them
   into enrolment, not a flat refusal.
4. Disabling requires password re-confirmation.
5. An admin clearing another user's 2FA is a **separate permission**
   (`user.disable_two_factor`) and a distinguishable activity entry — it is
   exactly what an attacker with helpdesk access would do.

## 5.5 Registration

1. Admins **cannot** be self-registered — `marketplace:create-admin` only.
2. Sellers start `Status::Pending`; customers start `Status::Active`.
3. Registration returns **no session** — verification or approval comes first.
4. Seller registration is switchable via `system.registration_enabled`.

---

# 6. Repositories (new)

Per `001_Architecture.md` §4 and the pattern Localization established in
Phase 1.

| Contract (Domain) | Implementation (Infrastructure) | Purpose |
|---|---|---|
| `UserRepositoryContract` | `UserRepository` | Type-scoped lookup, eager loads |
| `SessionRepositoryContract` | `SessionRepository` | Active sessions, pruning |
| `DeviceRepositoryContract` | `DeviceRepository` | Fingerprint resolution, trust |
| `LoginAttemptRepositoryContract` | `LoginAttemptRepository` | Failure counts, distinct IPs |

**`$with` declarations matter here** — strict mode makes lazy loading throw, and
`UserResource` reads four locale relations.

The detection queries currently living as statics on `LoginAttempt`
(`recentFailuresFor`, `distinctIpsFor`) move into `LoginAttemptRepository`.
Under ADR-011 they are arguably lightweight helpers, but they are aggregate
queries across rows rather than facts about one row.

---

# 7. Application layer

## 7.1 Actions to add

| Action | Transaction | Notes |
|---|---|---|
| `RequestPasswordResetAction` | no | Always the same response; rate limited |
| `ResetPasswordAction` | yes | Validates token, delegates to `ChangePasswordAction` |
| `VerifyEmailAction` | yes | Signed URL; idempotent |
| `ResendVerificationAction` | no | Rate limited |
| `UpdateProfileAction` | yes | Name, phone, locale preferences |
| `EnableTwoFactorAction` | yes | Wraps `generateSecret` + `confirm` |
| `DisableTwoFactorAction` | yes | Requires password confirmation |
| `TrustDeviceAction` | yes | |
| `ImpersonateUserAction` | no | → **Q4** |

## 7.2 Services

`AuthService` gains password-reset and verification orchestration.
`TwoFactorService` and `SessionService` are complete.

**`AuthService::classifyThreat()`** (Phase 7, formerly `isUnderAttack()`) runs on
the login failure path and dispatches `SuspiciousLoginDetected` when an address
crosses the configured thresholds. No longer dead code.

## 7.3 DTOs

ADR-021 — `DTO` suffix, `Domain/DTOs/`.

`PasswordResetRequestDTO`, `ResetPasswordDTO`, `UpdateProfileDTO`,
`TwoFactorChallengeDTO`, `TwoFactorEnrolmentDTO`.

---

# 8. Events

Nine exist. Three to add:

| Event | Dispatched by |
|---|---|
| `EmailVerified` | `VerifyEmailAction` |
| `PasswordResetRequested` | `RequestPasswordResetAction` |
| `SuspiciousLoginDetected` | `AuthService::classifyThreat()` on the failure path |

All extend `BaseEvent`, carry a correlation id, and are past tense (`004` §10).

The Activity module's `RecordIdentityActivity` subscriber gains handlers for
these three — **Identity must not call Activity directly**.

---

# 9. Notifications (new)

Extend `BaseNotification`. All are **security alerts**
(`isSecurityAlert(): true`), so they bypass opt-out preferences — a user must
not be able to mute the message telling them their password was reset.

| Notification | Channels | Trigger |
|---|---|---|
| `VerifyEmailNotification` | mail | Registration, resend |
| `ResetPasswordNotification` | mail | Reset requested |
| `PasswordChangedNotification` | mail + database | `PasswordChanged` |
| `NewDeviceLoginNotification` | mail + database | `UserLoggedIn` with `newDevice` |
| `SuspiciousLoginNotification` | mail + database | `SuspiciousLoginDetected` |
| `TwoFactorChangedNotification` | mail + database | `TwoFactorEnabled/Disabled` |

Rendered in the recipient's locale via `User::preferredLocale()`.

---

# 10. Jobs

None new. Notifications queue themselves via `BaseNotification`; `SendEmailJob`
covers anything outside a notification.

---

# 11. API surface

Base `/api/v1`. snake_case (ADR-008). ADR-009 envelope. UUID only. Resources
only. FormRequest for every endpoint. Policy for every authorised action.

## 11.1 Public — `throttle:auth` (5/min per email **and** per IP)

| Method | Path | Purpose |
|---|---|---|
| POST | `/auth/login` | ✅ exists |
| POST | `/auth/register` | ✅ exists |
| POST | `/auth/password/forgot` | Request reset |
| POST | `/auth/password/reset` | Redeem token |
| POST | `/auth/email/verify/{uuid}/{hash}` | Signed URL |
| POST | `/auth/email/resend` | Resend verification |

## 11.2 Authenticated — `throttle:api`

| Method | Path | Purpose |
|---|---|---|
| GET | `/auth/me` | ✅ exists |
| POST | `/auth/logout` | ✅ exists |
| PATCH | `/profile` | Update name, phone, locale |
| POST | `/profile/password` | Change password |
| GET | `/sessions` | ✅ exists |
| DELETE | `/sessions/{uuid}` | ✅ exists |
| DELETE | `/sessions` | ✅ exists — all others |
| GET | `/devices` | List devices |
| POST | `/devices/{uuid}/trust` | Trust |
| DELETE | `/devices/{uuid}` | Forget |
| GET | `/security/activity` | Own timeline — **belongs to the Activity module** (see note) |
| POST | `/two-factor/enable` | Generate secret + provisioning URI |
| POST | `/two-factor/confirm` | Confirm; returns recovery codes **once** |
| DELETE | `/two-factor` | Disable; requires password |
| POST | `/two-factor/recovery-codes` | Regenerate |
| POST | `/two-factor/email-otp` | Request an email OTP fallback (Q5) |

## 11.3 Admin — panel or `throttle:panel`

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/users` | `user.view_any` |
| GET | `/admin/users/{uuid}` | `user.view` |
| PATCH | `/admin/users/{uuid}` | `user.update` |
| POST | `/admin/users/{uuid}/reset-password` | `user.reset_password` |
| DELETE | `/admin/users/{uuid}/two-factor` | `user.disable_two_factor` |
| GET | `/admin/users/{uuid}/login-history` | `user.view_login_history` |
| POST | `/admin/users/{uuid}/impersonate` | `user.impersonate` → **Q4** |

### The activity timeline endpoint is not Identity's

`GET /security/activity` returns the user's own activity feed. `ActivityEntry`,
its policy and its resource are owned by the **Activity** module, and Identity
must not import them (§5.1).

So the endpoint is implemented in the Activity module's presentation layer, not
here — Activity already has the model, the policy and the read scope
(`ActivityEntry::scopeUserVisible()`). Placing it in Identity would either
duplicate that or breach the boundary.

Deferred to an Activity-module task; not part of Identity Phase 5. Devices and
sessions cover the security page's actionable surface in the meantime.

## 11.4 Error codes

`005` §25 — UPPER_SNAKE_CASE, stable, branched on by clients.

`INVALID_CREDENTIALS` · `ACCOUNT_UNVERIFIED` · `TWO_FACTOR_REQUIRED` ·
`TWO_FACTOR_INVALID` · `TWO_FACTOR_ENROLMENT_REQUIRED` · `VALIDATION_ERROR` ·
`RESET_TOKEN_INVALID` · `SESSION_NOT_FOUND` · `PASSWORD_UNCHANGED` ·
`CANNOT_MODIFY_SUPER_ADMIN`

`INVALID_CREDENTIALS` covers *every* non-disclosable failure. This is the
enumeration guarantee, and the mapping lives in `AuthenticationFailed` — never
in a controller.

---

# 12. Authorization

Existing permissions suffice: `user.*` resource verbs plus `user.impersonate`,
`user.reset_password`, `user.disable_two_factor`, `user.view_login_history`,
`user.assign_roles`, and `session.*` for all three actor types.

**To add:** `device.*` for all three actor types, ownership-scoped, plus a
`DevicePolicy`. `UserSessionPolicy` currently doubles for devices; a distinct
policy is clearer now that devices get their own endpoints.

## 12.1 Panel area abilities (post-freeze, Presentation only)

`user.manage_staff`, `user.oversee_sellers`, `user.oversee_customers` — one per
admin-panel account **area** (§12.2). They gate the *surface*, never a record:
every per-record decision still runs through the abilities above, including the
super-admin escalation guard.

**Why they exist.** `user.view_any` is a single grant that opens every account
of every type at once, so it cannot express the one distinction the split is
about: provisioning colleagues and granting them staff roles is not the same job
as answering a merchant's ticket. Super Admin and Admin hold all three; Support
holds the two oversight abilities and **not** the staff one — a helpdesk does
not hire.

**Cost.** Editor and Finance hold `user.view_any` for the API and now see no
account area in the panel; their API access is unchanged. If either is expected
to browse accounts in the panel, grant them the oversight abilities explicitly
in `RolePermissionSeeder`.

## 12.2 The admin panel's account areas

**Change category: "explicitly required by a later module" — an owner-approved
UX refinement, Presentation only.** No Domain, `app/Models` or Application code
was touched; every write still routes through `AdminUpdateUserAction`,
`RequestPasswordResetAction` and `TwoFactorService`, and every decision through
`UserPolicy`.

The single all-users `UserResource` became three type-scoped resources sharing
an abstract `AccountResource`, under one **Kullanıcılar** navigation group:

| Area | Model | What it can do |
|---|---|---|
| **Personel** (`StaffResource`) | `Admin` | List, view, **create**, edit, grant **staff roles**, suspend/reinstate |
| **Satıcılar** (`SellerResource`) | `Seller` | List, view, login history, suspend/reinstate, password reset, clear 2FA |
| **Müşteriler** (`CustomerResource`) | `Customer` | The same oversight set as Satıcılar |

**Why three resources and not one filter.** One list treated an administrator, a
merchant and a shopper as the same object with a different `type`, so every
control it offered had to be offered against all three. A staff role means
nothing on a merchant account, and a merchant's team is the merchant's to manage
(Organization §Ekip). Splitting by actor type is what makes each area's control
set honest — the oversight areas carry no role assignment because there is
nothing there to assign, not because a callback hid it.

**Staff creation and the escalation guard.** Creation mirrors
`marketplace:create-admin` exactly — same columns, same resolved locale
defaults, same `email_verified_at` stamp — because `RegisterUserAction` refuses
admins outright and there is no admin-creation action to reuse. The guard runs
twice: `UserPolicy` refuses a non-super-admin acting on a super-admin (the
existing rule, which covers every edit), and `StaffResource::assertRolesGrantable()`
refuses the **role** — Super Admin is absent from the options unless the actor
holds it, and the assertion rejects a forged payload. Hiding an option is a
courtesy; the assertion is the control.

**Cost.** `AccountResource` is an abstract Filament resource, which is one
indirection more than three flat classes. The alternative was triplicating the
table, the two security actions and the suspend/reinstate pair, where a fix
applied to two of three copies is the likely failure.

### Follow-ups (deliberately not built)

1. **Organization memberships on the Satıcılar detail.** Specified, then cut:
   Identity may not import Organization (`LayeringTest`), and the sanctioned
   cross-context path — extending `OrganizationAuthorizationContract` — is a
   Core/Organization change this Presentation-only change was not authorised to
   make. Owner-approved as a follow-up. When it lands it must be a read method
   on that contract returning plain arrays, never an import and never a raw read
   of Organization's tables.
2. **Set-password invitation for new staff.** Mail is not configured, so the
   operator sets an initial `StrongPassword::staff()` password, as the CLI does.
   Core's invitation infrastructure (ADR-031) is org-scoped today; forcing it
   here would be the wrong shape.

---

# 13. Testing plan

Coverage is a milestone target, not a blocker (`001` §24). These suites must
exist:

| Suite | Asserts |
|---|---|
| `LoginTest` | ✅ exists — enumeration, timing, guard isolation, attempts |
| `RegistrationTest` | Admin refusal, seller Pending, no session, composite email |
| `PasswordResetTest` | Token expiry per guard, no address disclosure, session cascade |
| `EmailVerificationTest` | Signed URL, idempotence, tampering |
| `TwoFactorTest` | Two-step enrolment, recovery single-use, hashed at rest |
| `SessionManagementTest` | Revocation kills the real session, ownership scoping |
| `DeviceTest` | Fingerprint stability, trust expiry, per-user isolation |
| `ProfileTest` | Locale FKs by code, no privilege escalation |
| `AuthorizationTest` | Every `UserPolicy` branch incl. the super-admin guard |
| `IdentityArchitectureTest` | Repositories implement contracts; DTO suffix |

`GuardIsolationTest` stays where it is — it tests `app/Models`, not the module.

---

# 14. Implementation plan

Ordered by dependency. Each phase is independently reviewable.

| # | Phase | Delivers | Blocks |
|---|---|---|---|
| **0** | ✅ **DONE** — API envelope (ADR-009) | `BaseController`, `BaseException`, `BaseRequest`, error codes, Resources, `lang/*/auth.php` | — |
| **1** | ✅ **DONE** — User name split (ADR-012) | ALTER migration with backfill, model accessor, DTO, request, resource, factory, command, Filament, `UserNameTest` | — |
| **2** | Repositories + contracts | 4 ports, 4 implementations, `$with`, bindings | Phases 3–6 |
| **3** | Password lifecycle | 2 actions, 2 DTOs, 2 requests, 2 endpoints, 2 notifications, `PasswordResetTest` | — |
| **4** | Email verification | 2 actions, 2 endpoints, 1 notification, 1 event, `EmailVerificationTest` | — |
| **5** | ✅ **DONE** — Profile + devices | `UpdateProfileAction`, `TrustDeviceAction`, `ForgetDeviceAction`, `DevicePolicy`, 5 endpoints, `ProfileTest` + `DeviceTest` | — |
| **6** | ✅ **DONE** — Two-factor API | 6 endpoints, `OtpStore`, `EmailOtpNotification`, `RequestEmailOtpAction`, `TwoFactorTest` — TOTP + recovery codes + email OTP (Q5) | — |
| **7** | ✅ **DONE** — Security signals | `classifyThreat()` on the failure path, `SuspiciousLoginDetected`, user + admin notifications, high-severity forensic audit entry (ADR-027), Activity handler (Q6) | Phases 3, 4 |
| **8** | ✅ **DONE** — Admin surface | 6 admin endpoints + Filament `UserResource` (list/edit + reset/disable-2FA actions, routing through the same Actions — no duplicated logic), `AdminUpdateUserAction`, admin requests, `UserPolicy::resetPassword`, `LoginAttemptResource`, `AuthorizationTest`. **User is an auditable aggregate** (before/after + reason). Secret-excluded changes (2FA, password reset) recorded as a `SECURITY_*` forensic timeline via events | Phases 1–2 |
| **9** | Impersonation | Session stack, banner, mandatory audit, one-click exit, no nesting, no Super Admin (Q4) | Phase 8 |

Phases 3–6 are parallelisable once 0–2 land. Phase 9 is deferred and blocks
nothing.

## 14.1 Phase 1 detail — the name split

Q1 confirmed. `users.name` → `first_name` (required) + `last_name` (nullable).

| Touchpoint | Change |
|---|---|
| Core migration | Replace `name`; backfill by splitting on the first space |
| `User` | `displayName()` computing `trim(first_name.' '.last_name)`. **No `full_name` column.** |
| `UserFactory` | `fake()->firstName()` / `fake()->lastName()` |
| `UserResource` | Emit `first_name`, `last_name`, `display_name` |
| `CreateAdminCommand` | Two prompts |
| Filament | `getFilamentName()` → `displayName()` |
| `RegisterRequest` / `RegisterUserDTO` | Two fields; `last_name` nullable |

## 14.2 Phase 6 detail — email OTP

Q5 adds a third factor below TOTP and recovery codes. It is a **fallback**, not
a peer: offered only when the user cannot produce a TOTP code, and it does not
change `hasTwoFactorEnabled()`.

Needs a short-lived single-use OTP store (cache-backed, Infrastructure — never
`cache()` from Domain, ADR-019), an `EmailOtpNotification`, and rate limiting
distinct from `throttle:auth`, since a request here is authenticated-adjacent.

---

# 14.4 Backlog

Approved future work. **No implementation now** — recorded so the seams are
known before code accretes around them.

## Password history

Configurable history (e.g. last 5 passwords) to prevent reuse.

Where it attaches: `ChangePasswordAction` and `ResetPasswordAction` are the two
write paths, so a `password_histories` table plus a check in both is the shape.
Both already route through the password lifecycle, so this is additive.

Note the interaction with the breach check: `StrongPassword::uncompromised()`
already blocks *globally* breached passwords; history blocks *this user's* prior
passwords. They are complementary, not redundant.

## Email change workflow

Changing an email address must become a **two-step** process:

```
current email  →  pending email  →  verification  →  activation
```

**The current email is never replaced until the new address is verified.** Until
then the account is reachable and loginable at the current address; the pending
address exists only as a target awaiting proof.

Shape when built: a `pending_email` column (plus its own verification token),
an action to request the change (emails the *pending* address), and an
activation step that runs on successful verification — swapping `pending_email`
into `email`, clearing `pending_email`, and leaving `email_verified_at` set.

Interacts with the manual-verification and force-change backlog items; build
them together or in a deliberate order.

## Force password change on next login

Required for administrator-created accounts and temporary credentials — a
`marketplace:create-admin` account should force a change on first sign-in.

Where it attaches: a `must_change_password_at` (or boolean) column on `users`,
checked in `LoginAction::verifyAccountState()`. A set flag produces a new
disclosable `AuthenticationFailed` reason — `PASSWORD_CHANGE_REQUIRED` — routing
the caller into the change flow rather than granting a session. It is
disclosable because the password was already proven correct, exactly like the
2FA-enrolment case.

`ChangePasswordAction` clears the flag.

## PasswordChanged as a documented extension point

`PasswordChanged` (Domain event) already exists and is dispatched by both
`ChangePasswordAction` and `ResetPasswordAction`. It is the sanctioned hook for
reacting to a password change without touching the write path.

Current subscribers: Activity (`RecordIdentityActivity`).

Anticipated subscribers, each a new listener in its own module, never a change
to the action:

- **Audit** — a high-value record write
- **Security notifications** — already handled inline via
  `PasswordChangedNotification`; could move to a listener
- **Analytics** — credential-hygiene metrics

The event carries scalars only (`userId`, `userUuid`, `viaReset`,
`keepSessionId`), never the password — it travels through the queue and the
audit log.

---

# 14.3 Risk detection belongs elsewhere

**`LoginAttemptRepository` must not become the home of risk detection.**

It exists to persist and query login history. `recentFailuresFor()` and
`distinctIpsFor()` are aggregate *queries* over that history — which is
repository work. Anything that **decides** whether a pattern is an attack is
not.

A dedicated abstraction is anticipated:

```
Domain/Contracts/RiskDetectionContract
Application/Services/RiskDetectionService
```

`AuthService::classifyThreat()` is the current two-signal heuristic (failure
count, distinct IPs) — enough for Q6, but living in a service that has no other
reason to know about scoring. It moves behind the contract when a richer risk
engine arrives (device reputation, geovelocity, impossible travel).

**Why keep it separate.** Risk scoring accretes: velocity, geography,
device novelty, known-breach corpora, and eventually a SIEM integration
(decided in Q6). Every one of those is a new input the repository has no
business knowing about. Leaving them to accumulate on the repository turns a
persistence class into a rules engine.

**No implementation now.** This is an architectural note recording where the
seam goes, so nobody adds a third heuristic to the repository in the meantime.

---

# 15. Out of scope

Organization membership · Store access · SSO / OAuth / social login ·
SCIM provisioning · WebAuthn / passkeys · SMS-based 2FA (no provider —
ADR-017) · Account merging · GDPR export and erasure

---

# 16. Confirmed decisions

All six open questions were ruled on before implementation.

## Q1 — User name structure — **implement ADR-012 now**

`first_name` (required) and `last_name` (**nullable**).

The platform serves both individuals and sole traders, and a sole trader may
have no surname.

Display name is **computed**:

```php
trim($user->first_name.' '.$user->last_name)
```

**Never store a separate `full_name` column.** A denormalised copy is a second
source of truth that drifts the first time one side is updated alone.

## Q2 — Reset and verification links — **REVISED, see ADR-025**

> **The original ruling was revoked.** It returned the reset token in the
> response body of an unauthenticated endpoint, which meant anyone knowing an
> email address could seize the account in two requests. It also contradicted
> §5.2 rule 5 — a response carrying a token for real accounts and none for
> missing ones is an existence oracle.

**Tokens never appear in an API response** (ADR-025). They travel out-of-band,
by email, to the mailbox their owner controls.

The backend stays frontend-agnostic through **configuration**, not exposure:

```php
'frontend' => [
    'url'                 => env('FRONTEND_URL'),
    'password_reset_path' => env('FRONTEND_PASSWORD_RESET_PATH', '/reset-password/{token}'),
    'email_verify_path'   => env('FRONTEND_EMAIL_VERIFY_PATH', '/verify-email/{id}/{hash}'),
],
```

The notification composes `https://site.com/reset-password/{token}`. A second
frontend — mobile app, admin SPA — needs one environment value, not a backend
change. That was the real requirement, and it is met.

**The response is identical whether or not the account exists:**

```json
{
    "success": true,
    "message": "If an account exists for this email address, password reset instructions have been sent."
}
```

No token. No user information. No timing differences.

The same rule applies to email verification.

## Q3 — Password reset scope — **one API for every account type**

All eight roles use the same Identity API: Customer, Seller, Seller Employee,
Admin, Support, Finance, Editor, Super Admin.

**All authentication flows are implemented by the Identity module.** The Admin
Panel provides a UI only — business rules stay in Identity.

**No duplicated authentication logic anywhere.** Two implementations of a
password reset is two places for the token expiry to drift, and one of them
will be the insecure one.

Per-guard token expiry still applies: 15 minutes for admins, 60 for everyone
else (`config/auth.php`).

## Q4 — Impersonation — **approved, later phase**

Not part of Identity Phase 1. Requirements when built:

- Session stack — the original user is preserved
- Audit log **mandatory**
- Visible admin banner while impersonating
- One-click exit
- **Never** allow nested impersonation
- **Never** allow impersonating a Super Admin

## Q5 — Two-factor fallbacks — **support all three**

Priority order:

1. **TOTP** — primary
2. **Recovery codes** — hashed, single-use
3. **Email OTP** — fallback

SMS may be added later, once a provider is chosen (ADR-017).

## Q6 — Risk detection — **notify both** — ✅ implemented (Phase 7)

`AuthService::classifyThreat()` runs on the login failure path (after the
attempt row is written, so the current failure counts) and, past the configured
thresholds, raises `SuspiciousLoginDetected` — at most once per cooldown, the
slot claimed atomically so concurrent failures cannot double-alert. Detection is
wrapped so a fault in it never turns a wrong password into a 500. Three
independent listeners react:

- **The affected user** — `SuspiciousLoginNotification` (mail + database, an
  unignorable security alert). Skipped when the address has no account.
- **A high-severity audit event** — `RecordSecurityAudit` writes a `SECURITY_*`
  entry at `HIGH` (brute force) or `CRITICAL` (credential stuffing) via the
  forensic store (ADR-027). Identity does not import Audit; Audit subscribes.
- **Administrators** — `SecurityAlertNotification` to the holders of the
  `security.receive_alerts` permission (Super Admin + Support by default), not
  every admin. This is the first-level authorization gate; it scales from five
  admins to hundreds. Per-user preferences will sit *behind* it when the
  Notification module lands (backlog in `docs/notifications.md`), never replace
  it. The **owner is always notified** regardless.

Thresholds and the cooldown are configuration
(`marketplace.security.suspicious_login.*`). Brute force (concentrated failures)
and credential stuffing (failures across many IPs) are classified separately —
`App\Shared\Enums\LoginThreatKind` — because the trail grades them differently.

`isUnderAttack()` was replaced by `classifyThreat()`, which returns *which*
threat rather than a bare bool, so the audit severity can follow the shape.

Future versions may integrate SIEM providers — the `severity` scale already maps
onto PSR/syslog levels for exactly that.

---

# 17. Acceptance criteria

Identity is complete when:

- ✓ All 15 endpoints exist, policy-guarded and FormRequest-validated
- ✓ Every response uses the ADR-009 envelope and snake_case
- ✓ Four repositories implement their contracts
- ✓ Password reset works per guard with correct token expiry
- ✓ Email verification works and is idempotent
- ✓ 2FA enrolment, challenge, recovery and disable all work
- ✓ Session revocation destroys the real session and token
- ✓ Six notifications deliver in the recipient's locale
- ✓ Ten test suites exist and pass
- ✓ `make check` passes
- ✓ `docs/authentication.md` updated

---

END OF FILE
