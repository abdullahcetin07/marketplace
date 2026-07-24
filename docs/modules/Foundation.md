# Foundation Module Group Specification
Version: 2.0

Synchronized with the Architecture Decision Record (ADR-002, 012, 013, 017).

---

# Structure

**Foundation is a module GROUP, not a module** (ADR-002).

**No `app/Modules/Foundation/` directory exists or should be created.**

The group comprises seven modules. Each owns its own models, migrations,
services, policies, events, jobs, DTOs, tests and documentation:

| Module | Owns | Documentation |
|---|---|---|
| **Identity** | Sessions, devices, login history, 2FA, the auth flow | [authentication.md](../authentication.md) |
| **Localization** | Languages, countries, currencies, timezones, translations | [localization.md](../localization.md) |
| **Settings** | Business configuration, typed and cached | [settings.md](../settings.md) |
| **Audit** | Field-level record history, append-only | [audit.md](../audit.md) |
| **Activity** | User timeline, append-only | [audit.md](../audit.md) |
| **Media** | Upload validation, optimisation, deletion | [media.md](../media.md) |
| **Notification** | Channels, preferences, queued delivery | [notifications.md](../notifications.md) |

`app/Modules/README.md` is the index. Per-module documentation lives in
`docs/`, not in a README inside the module — one place per module.

---

# Purpose

Foundation provides the core infrastructure used by every other module.

No business-specific logic belongs here.

Every future module depends on Foundation.

---

# Responsibilities

- Authentication
- Authorization
- User Management
- Roles
- Permissions
- Localization
- Countries
- Currencies
- Languages
- Timezones
- Settings
- Audit Logs
- Activity Logs
- Notification Infrastructure
- Media Infrastructure

---

# Business Rules

Foundation contains no marketplace logic.

Foundation must never reference:

- Products
- Offers
- Orders
- Stores
- Organizations
- Inventory

The dependency runs one way: later modules depend on Foundation, never the
reverse.

---

# Models

| Model | Module | Table |
|---|---|---|
| `User` (+ `Admin`, `Seller`, `Customer`) | *`app/Models`* — see ADR / §Identity Placement | `users` |
| `UserSession` | Identity | `user_sessions` |
| `UserDevice` | Identity | `user_devices` |
| `LoginAttempt` | Identity | `login_attempts` |
| `Language` | Localization | `languages` |
| `Country` | Localization | `countries` |
| `Currency` | Localization | `currencies` |
| `Timezone` | Localization | `timezones` |
| `Translation` | Localization | `translations` |
| `Setting` | Settings | `settings` |
| `AuditEntry` | Audit | `audit_entries` |
| `ActivityEntry` | Activity | `activity_entries` |
| `NotificationPreference` | Notification | `notification_preferences` |
| `Role`, `Permission` | *Spatie package* | `roles`, `permissions` |
| `Media` | *Media library package* | `media` |

`User` lives in `app/Models/`, **not** inside the Identity module —
`001_Architecture.md` §6 explains why (Core and Shared reference it; placing it
in a module would invert the dependency graph).

---

# User

Fields

UUID

first_name (required)

last_name (nullable)

display_name (computed — never a column, ADR-012)

Email

Phone

Password

Avatar

Status

Language

Currency

Timezone

Last Login

Email Verified At

Two Factor Enabled

Created At

Updated At

Deleted At

---

# User Rules

**Email uniqueness is composite: `(type, email)`** (ADR-012).

The same address may exist once per account type. One human may legitimately be
both a Seller and a Customer, and forcing them to invent a second address is
hostile. A global unique index would also let anyone probe whether an address
belongs to an admin.

Phone unique (nullable).

Soft Delete enabled.

UUID exposed publicly.

Password hashed.

Never expose internal ID.

Locale preferences (`language_id`, `country_id`, `currency_id`, `timezone_id`)
are BIGINT foreign keys into the Localization lookup tables — never enums, never
UUID foreign keys (ADR-004, ADR-006).

---

# Authentication

Laravel Sanctum.

Email login.

Remember me.

Password reset.

Email verification.

Session management.

Device tracking.

Two-factor ready.

Three independent guards — `admin`, `seller`, `customer` — over one `users`
table, isolated by a per-subclass global scope on `users.type`.

---

# Authorization

Spatie Permission.

Permissions are dynamic and derived from a registry.

Never check role names in code. Always use permissions.

Permissions and roles are guard-scoped: the same name on two guards is two
distinct records.

Wildcard permissions are disabled.

---

# Roles

Nine default roles (ADR-013):

Super Admin

Admin

Editor

Category Manager

Support

Finance

Seller

Seller Employee

Customer

**Category Manager remains part of the system** (ADR-013).

**Super Admin and Admin are distinct.** Super Admin bypasses every policy;
Admin holds an enumerated permission set.

Roles are configurable and referenced by name from configuration, never by id.

---

# Permissions

Every permission is stored in the database.

Never hardcode permissions — register a resource and let the verb set be
derived.

---

# Countries

Lookup table (ADR-006).

ISO 3166.

Phone Code.

Currency Relation.

Timezone Relation.

Flag.

`is_active` (ADR-015 — lookup tables do not carry a workflow `status`).

---

# Currency

Lookup table (ADR-006).

ISO 4217.

Symbol.

Symbol position.

Decimal Digits.

Decimal and thousands separators.

Exchange Rate — `DECIMAL(20,10)` (ADR-005: DECIMAL is correct for rates).

`is_active`.

**Money amounts elsewhere are integers of minor units** (ADR-005).

---

# Language

Lookup table (ADR-006).

ISO Code.

Locale (BCP-47).

Native Name.

RTL Support.

`is_active`.

Unlimited languages supported. Adding one is an operator action, not a release.

---

# Timezone

Lookup table (ADR-006).

IANA name.

Display label.

`is_active`.

---

# Settings

Groups

General

Company

SEO

Email

SMS

Localization

Media

Security

Performance

System

Support

Data Types

string

integer

boolean

json

text

Stored as one text column plus a type column — the type is what makes `false`,
`0` and `"0"` distinguishable on read.

---

# Audit Log

Model: `AuditEntry` · Table: `audit_entries`

Track

Actor

Action

Target Type

Target UUID

Old Values

New Values

IP

User Agent

Timestamp

**Audit logs are immutable** — the model refuses updates and deletes. Retention
pruning bypasses the model.

Actor references use SET NULL, never CASCADE: the trail must outlive the actor
(ADR-014).

---

# Activity Log

Model: `ActivityEntry` · Table: `activity_entries`

Track

Login

Logout

Password Change

Profile Update

Permission Changes

Organization Join (future)

Store Join (future)

Append-only, like the audit trail. Populated by subscribing to Identity's
domain events — Identity never calls Activity directly.

---

# Notification Infrastructure

Database notifications.

Email notifications.

SMS ready.

Push ready.

Queue ready.

**Providers are deferred** (ADR-017). Channels, contracts, jobs and the
`NotificationType` enum exist; no SMS or push driver is bound. A channel with no
provider throws rather than silently discarding a message.

`NotificationType` is an **enum, not a lookup table** (ADR-006).

---

# Media Infrastructure

Supports

Images

Documents

Videos

Storage

S3 Compatible

Image optimization.

Responsive images.

Future CDN support.

Public and private assets use **separate buckets**, so a policy mistake on the
public bucket cannot expose identity documents.

---

# Events

UserCreated

UserUpdated

UserDeleted

UserLoggedIn

UserLoggedOut

PasswordChanged

TwoFactorEnabled

TwoFactorDisabled

SessionRevoked

SettingUpdated

LanguageChanged

CurrencyChanged

---

# Jobs

SendEmail

SendSMS

OptimizeImage

DeleteMedia

GenerateThumbnail

---

# Policies

Every model has a Policy.

Controllers never authorize.

Policies handle authorization and check permissions, never role names.

`BasePolicy::owns()` returns **false** by default — any seller- or
customer-facing policy must override it, or it denies everything. A loud
failure, which is the correct direction.

---

# Validation

Every endpoint uses FormRequest.

Validation never occurs in Controllers.

`BaseRequest::authorize()` returns **false** by default.

---

# API

Base path

/api/v1

Every endpoint uses Resources.

Every endpoint uses UUID.

Responses use snake_case (ADR-008) and the canonical error envelope (ADR-009).

REST endpoints never expose Eloquent models (ADR-010).

---

# Tests

Feature Tests

Unit Tests

Architecture Tests

Factories required.

Coverage goals are milestone targets. **Coverage is not a sprint blocker**
(`001_Architecture.md` §24).

---

# Documentation

The module **group** produces, per module, in `docs/`:

- The module document (`localization.md`, `settings.md`, …)

And at group level:

- `README.md` — `app/Modules/README.md`, the index
- `DATABASE.md` — schema reference across the seven modules
- `API.md` — endpoint reference
- `BUSINESS_RULES.md`
- `CHANGELOG.md`
- `TESTING.md`

---

# Acceptance Criteria

Foundation is complete when:

✓ User authentication works

✓ Permissions work

✓ Countries are configurable

✓ Languages are configurable

✓ Settings are configurable

✓ Audit logging works

✓ Activity logging works

✓ Notification infrastructure exists

✓ Media infrastructure exists

✓ Tests pass

✓ Documentation complete

---

# Out of Scope

Organization

Store

Product

Offer

Inventory

Pricing

Commission

Order

Payment

Shipment

Deferred API surface (ADR-017): Bulk APIs · Webhooks · Idempotency Keys ·
Advanced Search · External Providers.

---

# Questions for Implementation

If any requirement conflicts with a higher-precedence document (ADR-003):

Stop implementation.

Explain the conflict.

Wait for approval.

Never make assumptions.

---

END OF FILE
