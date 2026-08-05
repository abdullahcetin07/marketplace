# MarketplaceOS Architecture
Version: 2.0

**Authoritative architecture document** (ADR-001).

Superseded and absorbed: `docs/architecture.md` (removed). Its decisions,
their costs and its amendment history are carried below.

This document records decisions **and what each one costs**. A decision
recorded without its trade-off is not a decision, it is a preference.

---

# 1. Purpose

MarketplaceOS is an Enterprise Marketplace Platform.

It is NOT a simple e-commerce application.

The system is designed for:

- Marketplace
- Multi Vendor Commerce
- Single Vendor Commerce
- B2B
- B2C
- Wholesale
- Dropshipping
- Subscription Commerce

The architecture must remain scalable, modular and maintainable.

---

# 2. Architecture Style

The system follows a **Modular Monolith** architecture.

Every business capability is developed as an isolated module. Modules
communicate through Services, Events and Contracts. Direct module dependencies
must be minimized.

**Why not microservices.** A marketplace's aggregates are densely coupled by
nature: an order references offers, which reference products, which reference
categories and stores. Splitting those across services replaces a foreign key
with a network call and a transaction with a saga. That price is worth paying at
a scale this platform is not at, and may never reach.

**Cost.** Nothing enforces the boundaries at runtime — a developer *can* import
another module's service directly. That is why `tests/Architecture/LayeringTest.php`
exists: the boundary is enforced by a failing build instead of a network error.

**Exit path.** Because modules already communicate through domain events rather
than direct calls, extracting one later means replacing an in-process listener
with a queue consumer, not rewriting the module.

---

# 3. Layers

Every module follows the same four layers.

```
Domain/          What the business is.       No framework. No Eloquent.
Application/     What the business does.     Services, actions, jobs.
Infrastructure/  How it is stored and found. Repositories, observers, search.
Presentation/    How it is exposed.          Controllers, policies, resources.
```

Dependencies point **inward**. Presentation may use Application, which may use
Domain. Infrastructure implements interfaces that Domain declares. Domain
depends on nothing.

Presentation never accesses Infrastructure directly.

Business rules belong only to Domain/Application.

Repositories belong to Infrastructure.

**Why this direction.** It is what makes business rules testable without a
database. `RepositoryContract` lives in Domain while `BaseRepository` lives in
Infrastructure precisely so a service can be unit-tested against an in-memory
fake.

**Cost.** More files, and an interface for things that only ever have one
implementation. Accepted deliberately — the alternative is business logic that
can only be tested by booting the whole framework, which is how test suites
become too slow to run.

---

# 4. Module Independence

Every module must be self-contained.

Each module owns:

- Models
- Migrations
- Repositories
- Services
- Policies
- Events
- Jobs
- DTOs
- Tests
- Documentation

Never place business logic inside Shared folders.

---

# 5. Dependency Rules

**Allowed**

- Presentation → Application
- Application → Domain
- Infrastructure → Domain

**Forbidden**

- Presentation → Infrastructure
- Domain → Presentation
- Domain → Application
- Module → Module database access

Never query another module's database directly. Communication between modules
happens through Services or Events.

**Application → Infrastructure** is permitted only through a Domain-declared
contract. A service type-hints `RepositoryContract`, never a concrete
repository. Without that indirection the service cannot be tested against a
fake, which is the entire reason the contract exists (§3).

## 5.2 Domain layer purity (ADR-019)

The Domain layer may not reach the container for infrastructure. The rule
covers **global helper functions as well as Facade classes** — a helper
resolving the same binding is the same violation.

| | Allowed in Domain | Forbidden in Domain |
|---|---|---|
| Helper | `now()`, `config()` | `cache()`, `request()`, `encrypt()`, `decrypt()` |
| Why | A clock reading and a static array. No I/O, no state. | Real I/O, request state and key material — the things that make a Domain class untestable without booting the framework. |

Where the work belongs instead:

- **Caching** → Infrastructure. Repositories or dedicated services.
- **Encryption** → Infrastructure. An Eloquent cast or a decorator.
- **HTTP request access** → Presentation. Pass a context object inward; never
  pull one from the container inside Domain.

`auth()` is **not** on the forbidden list (ADR-024). It represents the
authenticated Identity context, not infrastructure access.

Full rule: `002_Coding_Standards.md` §30.1.

## 5.3 ORM metadata exception (ADR-023)

Eloquent is an Active Record ORM, so a Domain model must name some
Infrastructure classes for the ORM to function. This is permitted, **narrowly
and declaratively only**:

| Allowed — metadata | Forbidden — dependency |
|---|---|
| A custom cast named in `casts()` | Calling a Service |
| An observer passed to `observe()` | Calling a Repository |
| A global scope passed to `addGlobalScope()` | Cache, HTTP, Mail, Queue, Crypt |

**The test:** *naming* a class is metadata; *calling a method on it* is a
dependency. The first is allowed, the second is not.

Sanctioned use: `Settings\Domain\Models\Setting` names
`Settings\Infrastructure\Casts\EncryptedSettingValue`.

## 5.1 Module dependency exceptions

Modules never import each other, with three recorded exceptions. Each is
asserted individually in `tests/Architecture/LayeringTest.php`.

| Exception | Direction | Why |
|---|---|---|
| Anything → **Localization** | read | Platform-wide reference data. Duplicating it per module defeats promoting it to tables (§9). |
| **Settings** → Audit | trait | Settings changes are dispute evidence. Audit reaching into Settings is the worse direction. |
| **Activity** → Identity **events only** | subscribe | The consumer knows the producer's event contract, never the reverse. |

---

# 6. Identity Placement

`App\Models\User` and its three subclasses live in `app/Models/`, **not** inside
the Identity module. The Identity module owns everything *around* identity —
sessions, devices, login history, the authentication flow.

**Why.** `app/Core/Presentation/Policies/BasePolicy` and the `HasCreator` /
`HasUpdater` traits in `app/Shared` both reference `User`. If `User` lived in a
module, Core and Shared would depend on that module and the layering test would
correctly fail. Every other module would depend on Identity transitively too.

The dependency graph therefore has one tier above the modules:

```
app/Models          may depend on modules   (User hangs relations off them)
app/Modules/*       may depend on app/Models, app/Core, app/Shared
app/Core            depends on nothing but app/Shared
app/Shared          depends on nothing
```

**Cost.** `User` imports several modules, so renaming a module's model namespace
touches it. And `User` ↔ Identity is a cycle at the namespace level — harmless
in PHP, but a weakened boundary. Accepted, because the alternative is either
`$user->sessions` not existing or six modules depending on a seventh.

---

# 7. Naming Rules

| Context | Language |
|---|---|
| Source code | English |
| Database | English |
| API | English |
| Variables | English |
| Comments | English |
| UI | Turkish (default) |

Localization must support unlimited languages.

Full conventions: `004_Naming_Conventions.md`.

---

# 8. Database Standards

**Primary key:** BIGINT auto-increment.
**Public identifier:** UUID, unique and indexed.

Every public URL uses the UUID. Every foreign key references the BIGINT.
**UUID is never used as a foreign key** (ADR-004).

```
users
  id    BIGINT PRIMARY KEY
  uuid  UUID UNIQUE
```

**Why not UUID primary keys.** Random UUIDs as a clustered key fragment B-tree
indexes and inflate every foreign key from 8 to 16 bytes. With hundreds of
tables and millions of rows that cost compounds. Keeping the bigint internally
and the UUID externally gets both properties.

**Why expose a UUID at all.** Sequential ids leak business volume — a competitor
registers, places one order, and reads off the platform's order count. They also
make enumeration trivial.

**Cost.** An extra unique index per table, and a UUID lookup rather than a
primary-key lookup on the way in.

Full detail: `003_Database_Standards.md`.

---

# 9. Lookup Tables

The following are lookup tables (ADR-006):

- Countries
- Currencies
- Languages
- Timezones
- Tax Rates
- Payment Methods
- Shipping Methods

**Notification channels are NOT a lookup table** — `NotificationType` is an enum
(ADR-006, §10).

These are business data and must NOT be implemented as enums.

**Why.** They carry mutable operational data an enum cannot hold: an exchange
rate that moves hourly, whether a country is currently shipped to, a phone code,
a flag. And "support unlimited languages" is not expressible as an enum — every
new locale would be a release.

**Cost, stated plainly.** Three things are genuinely worse:

1. No exhaustiveness checking. Code branching on a currency code needs a
   fallback path, where a `match` on an enum did not.
2. Reads hit the database. Mitigated by caching every lookup and flushing on
   write via model observers.
3. Referential integrity replaces type safety. A bad `currency_id` is caught by
   a foreign key at write time rather than by PHPStan at author time.

Accepted because the alternative — a deploy to add Arabic, or to correct a EUR
rate — is worse.

**The seam.** `config/marketplace.php` holds ISO *codes*, not rows: which
currency the installer marks default, what locale to fall back to. That is
bootstrap data — what the application believes before the tables are readable.
The live default is the `is_default` column on the row, and exactly one row may
hold it, enforced by a partial unique index rather than only a model hook.

Lookup tables use `is_active`, not a workflow `status` (ADR-015).

---

# 10. Enums

Enums are reserved for **immutable business concepts** — where adding a case
requires writing code to handle it.

Examples:

- UserStatus
- ProductStatus
- OfferStatus
- OrderStatus
- StoreStatus
- MediaType
- NotificationType
- ActivityType
- CommissionType
- DiscountType
- RuleOperator

Enum names do **not** carry an `Enum` suffix (ADR-007). `OrderStatus`, never
`OrderStatusEnum`.

**Why.** A value in an enum is exhaustively checked by PHPStan — a new case
causes a compile-time failure everywhere it must be handled. A
`NotificationType` cannot be "enabled" by an operator, because enabling it means
writing a driver. State machines live on the enum (`allowedTransitions()`), so
transition rules are unit-testable with no database.

**Cost.** Adding a case requires a deploy — which is correct, because handling
it required code anyway.

The decision test, in one line: **if an operator must enable it without a
release, it is a table; otherwise it is an enum.**

---

# 11. Money

Money is stored as an **integer of minor units** (kuruş, cents). Never a float,
never a decimal read into a PHP float (ADR-005).

```
1299.90 TL  →  129990
```

`DECIMAL` is used only for:

- Exchange rates
- Tax rates
- Commission percentages
- Discount percentages

API responses format money as **decimal strings** — `"price": "1299.90"`
(ADR-005, `005_API_Standards.md` §28).

**Why.** `0.1 + 0.2 !== 0.3` in binary floating point. On a platform that
computes commission on every order, that error compounds into real money and
into reconciliation disputes that take days to unpick.

`Currency::decimal_places` is the exponent per currency rather than an assumed
2, so a zero-decimal currency can be added without auditing every call site.

---

# 12. Actions vs Services

| | Owns a transaction | Public methods | Named |
|---|---|---|---|
| **Action** | yes | one (`handle`) | verb + noun: `ApproveStoreAction` |
| **Service** | no | several | aggregate: `StoreService` |

An action is one atomic use case. A service composes actions and repositories
into the API a module presents to the outside.

**The test:** if you cannot name it with one verb and one noun, it is not an
action. Make it a service that calls several actions.

**Why actions own the transaction** rather than services: a service that opens a
transaction around three actions holds locks for the duration of all three,
including any HTTP calls they make. Pushing the boundary down keeps transactions
short, which is the single biggest lever on write throughput under contention.

Side effects — mail, webhooks, indexing — go in `BaseAction::after()`, which
runs **after commit**.

---

# 13. Repositories

Not to abstract the ORM — PostgreSQL is not going anywhere. To **contain query
vocabulary**.

Query logic written inline in controllers and Filament resources gets
copy-pasted, and the copies drift. Six months later "active offers" means three
subtly different things in three files. A repository is the one place a module's
queries live, and it is the natural home for the default eager loads that keep
strict mode happy (`BaseRepository::$with`).

**Cost.** An extra indirection for trivial lookups. Accepted; the alternative
degrades predictably and this does not.

## 13.1 Repositories are the only persistence abstraction callers see

A repository **may** use Eloquent internally — that is its job.

A **caller** must never depend on:

- the Query Builder
- ORM-specific APIs
- lazy loading
- any Eloquent implementation detail

| A service may receive | A service may not receive |
|---|---|
| A model, a collection, a paginator, a DTO | A `Builder` or `QueryBuilder` |
| A concrete result | A relation object to continue querying |
| — | Anything expecting the caller to `->with()` or `->where()` |

**Why.** A repository that returns a `Builder` has abstracted nothing — the
caller still writes the query, just somewhere less findable. And strict mode
makes lazy loading *throw*, so a caller traversing an unloaded relation is a
runtime failure that only appears on the code path nobody tested. Eager loads
belong on the repository (`$with` / `WITH`), decided once.

This refines §13 rather than adding a rule; no ADR was raised for it.

---

# 14. Events

Modules never call each other's services. When the Store module approves a
store it dispatches `StoreApproved`; whatever the Catalogue module needs to do
in response is a listener it registers itself.

Every important action produces an Event:

UserCreated, OrganizationCreated, StoreCreated, ProductCreated, OfferCreated,
OrderCreated, CommissionCalculated, InventoryReserved, PaymentCompleted.

**Why.** Direct calls create a dependency graph that becomes a cycle within
about three modules. Events make the dependency one-directional and optional: a
module can be removed and its events simply have no listeners.

**Cost.** Control flow becomes non-obvious — "what happens when a store is
approved?" is answered by grepping for the event, not by reading a call stack.
Mitigated by `BaseEvent` carrying a correlation id, so a full trace is one log
query away, and by keeping listener registration explicit
(`shouldDiscoverEvents()` returns `false`).

---

# 15. Audit and Activity

Two tables, two questions, two retention periods.

| | `audit_entries` | `activity_entries` |
|---|---|---|
| Answers | "What changed on **this record**?" | "What did **this user** do?" |
| Shape | field-level before/after diff | one readable sentence |
| Read by | a lawyer, during a dispute | a customer, on their security page |
| Retention | 730 days | 365 days |

**Why not one table.** They are read by different people for different reasons.
A merged table answers neither without a filter, and forces one retention policy
onto two kinds of evidence with different legal weight.

Both are **append-only**, enforced by the model rather than by convention. An
editable audit trail is not an audit trail. Retention pruning bypasses the model
with a query-builder delete.

**Cost.** Two writes for actions that are both — a password change produces an
activity entry *and* an audit entry. Deliberate: the activity entry survives the
audit retention window.

---

# 16. Settings vs Configuration

| | Holds | Read before boot | Changing it |
|---|---|---|---|
| `config/*.php` | what the **application** needs | yes | deploy |
| `settings` table | what the **business** decides | no | click |

A value required before the framework boots can never be a setting — reading one
needs the database connection that config already defined.

Stored as **one text column plus a type column**, not a column per type. The
cost is that `false`, `0` and `"0"` are indistinguishable coming out, which is
exactly why the type is stored and applied on read.

---

# 17. Authorization

Authorization uses Policies.

Permissions are dynamic and **derived** from a registry, never hand-written.

Never use Role IDs — roles are referenced by name, from configuration.

Never authorize inside Controllers.

Policies check **permissions**, never role names, with one documented exception:
a privilege-escalation guard may check "is this target more privileged than the
actor", because that is not expressible as a permission.

Permissions and roles are **guard-scoped**: the same permission name on two
guards is two distinct records with distinct meanings.

Wildcard permissions are **disabled**. Granting `store.*` today would silently
grant `store.force_delete` the moment someone adds it.

---

# 18. Validation

Validation uses FormRequest.

Never validate inside Controllers.

`BaseRequest::authorize()` returns **false** by default. Laravel's default is
true, which means a forgotten override silently opens an endpoint.

---

# 19. Business Logic

Business logic belongs inside Services.

Repositories never contain business logic.

Controllers never contain business logic.

Domain models may carry relationships, accessors, mutators, scopes and
lightweight helper methods; business **workflows** belong to Services
(ADR-011).

---

# 20. Jobs

Heavy operations must use queues:

Image Processing, Search Indexing, SEO Generation, AI Generation, Imports,
Exports, Email, SMS, Push Notifications.

Never perform heavy work synchronously.

Queues are separated by latency profile so one cannot starve another — a bulk
import must never delay a password reset email.

---

# 21. API Standards

REST first. `/api/v1`.

Standard response format. Standard error format (ADR-009).

Responses use **snake_case** (ADR-008).

UUID is exposed publicly. Internal IDs remain hidden.

REST APIs must never expose Eloquent models directly. Presentation layers
(Filament, Console, Admin UI) may use Eloquent models where appropriate
(ADR-010).

Full detail: `005_API_Standards.md`.

---

# 22. Security

Policies · CSRF · Rate Limiting · Sanctum · 2FA Ready · Audit Logs ·
Activity Logs · Password Policies.

Secrets never stored in source code.

**Credentials leave out-of-band only** (ADR-025). Password reset and email
verification tokens — and any future credential such as an organization
invitation or API key — must never appear in an API response body. They travel
through the channel their owner controls. The backend stays frontend-agnostic
via `marketplace.frontend.*` configuration, not by exposing the token.

---

# 23. Performance

**Strict mode is on.** Lazy loading, silent attribute discarding and
missing-attribute access all throw in development and CI; in production they are
logged, not thrown.

**Why day one.** Turning this on in a mature codebase means fixing hundreds of
latent N+1 queries simultaneously, which nobody ever schedules. Turning it on
now means each one is caught by whoever writes it.

**Why production differs.** A missed eager-load should be a warning in a log,
not a 500 for a customer.

Use eager loading. Index every foreign key. Use cursor pagination where
appropriate.

---

# 24. Testing

Every module requires:

- Feature Tests
- Unit Tests
- Architecture Tests

Coverage goals are milestone targets. **Coverage is NOT a sprint blocker.**

Architecture tests are the enforcement mechanism for this document.
Documentation describing a layering rule is a suggestion; a failing build is a
rule. If one starts failing, the question is *"did we mean to change that
decision"*, not *"how do I silence it"*.

---

# 25. Product Model

Marketplace owns Products. Stores never own Products. Products are shared.
Stores own Offers.

```
Master Product  →  Offer  →  Inventory  →  Order
```

This rule is absolute.

---

# 26. Module Dependency Direction

The platform's modules form a **strict one-directional chain**. Each layer
depends on the one before it and never the reverse.

```
Identity  →  Organization  →  Store  →  Catalog  →  Offer  →  Order
```

| Module | Depends on | Never depends on |
|---|---|---|
| Identity | nothing above Foundation | Organization, Store, Catalog, Offer, Order |
| Organization | Identity | Store, Catalog, Offer, Order |
| Store | Organization | Catalog, Offer, Order |
| Catalog | Store | Offer, Order |
| Offer | Catalog | Order |
| Order | Offer | — |

This is not a new decision. It consolidates §5 (dependency rules), §5.1 (module
isolation), §25 (product model) and `docs/modules/Foundation.md`, which already
forbids Foundation referencing any marketplace module. Stating the whole chain
in one place removes the need to reconstruct it from fragments.

## 26.1 The invariant this produces

**A `User` is complete on its own.** It may exist with no Organization, no
Store and no seller profile.

That follows directly from Foundation's prohibition: if Identity cannot
reference Organization, a User cannot require one. Registration, login,
password reset, 2FA and session management must all work for an account that
belongs to nothing.

The practical consequence: **never** add a non-nullable `organization_id` to
`users`, and never make an Identity flow conditional on membership. Membership
is Organization's concern, expressed as a pivot Organization owns.

## 26.2 Organization and Store

```
Organization  →  Stores  →  Offers
```

One Organization can own many Stores. One Store belongs to exactly one
Organization.

Organization is the **legal entity**. Store is the **commercial storefront**.
A Seller is a person who authenticates; the Organization is what trades.

## 26.3 How the chain is crossed

Downward is a direct dependency; **upward is events only** (§14). When Order
needs to know an Offer changed, Offer dispatches and Order listens — Offer
never calls into Order.

Asserted per module in `tests/Architecture/LayeringTest.php` as each one lands.

---

# 27. Foundation Structure

**Foundation is a module group, not a module** (ADR-002).

No `app/Modules/Foundation/` directory exists or should be created.

The group comprises seven modules, each owning its own models, migrations,
services, policies, events, jobs, DTOs, tests and documentation:

Identity · Localization · Settings · Audit · Activity · Media · Notification

Specification: `docs/modules/Foundation.md`.

---

# 28. Document Priority

Per ADR-003:

1. `CLAUDE.md`
2. `Architecture_Decision_Record.md`
3. `001_Architecture.md`
4. `003_Database_Standards.md`
5. `002_Coding_Standards.md`
6. `004_Naming_Conventions.md`
7. `005_API_Standards.md`
8. Module Specifications

**Sprint prompts never override documentation.**

When a sprint brief conflicts with this chain: STOP, report, wait for an
explicit amendment. Never pick a side silently (ADR-018).

---

# 29. Architecture Decision Records

All approved decisions are recorded in:

`docs/Architecture_Decision_Record.md`

The ADR takes precedence over this document until this document is updated to
match it (ADR-001).

Amending a decision means updating the ADR **and** the amendment log below in
the same change.

---

# 30. Development Process

```
Analysis → Specification → Architecture Review → Implementation
        → Self Review → Testing → Documentation → Approval
```

No implementation starts without an approved specification.

---

# Decisions deliberately deferred

| Deferred | Until | Why |
|---|---|---|
| Multi-tenancy / marketplace-per-region | A second region is real | Retrofitting a tenant key is mechanical; guessing its shape is not |
| Read replica routing | Read load is measured | `pgsql_read` connection is configured and unused |
| OPcache preloading | Measured, not assumed | Fragile across Laravel versions; failure mode is hard to diagnose |
| Event sourcing on orders | Order module design | Audit trail meets today's needs |
| API rate limits per plan | Commercial tiers exist | Named limiters are in place and take a config value |
| SMS and Push providers | A provider is chosen | Channels, jobs and enum cases exist; drivers throw `NotImplemented` |
| Image conversion pipeline | Product media is designed | Job and disks exist; conversions declared, not tuned |
| Bulk APIs, Webhooks, Idempotency Keys, Advanced Search, External Providers | Post-Foundation | ADR-017 |

---

# Amendment log

| Sprint | Decision | Change |
|---|---|---|
| 1 | §9 Enums vs lookup tables | **Amended.** Country, Currency, Language became tables |
| 1 | §8 Public identifiers | **Reaffirmed.** BIGINT PK + UUID public id; "UUID primary key" overruled |
| 1 | §6 Identity placement | **Added** |
| 1 | §15 Audit vs Activity | **Added** |
| 1 | §16 Settings vs config | **Added** |
| 2 | ADR-001 Architecture documents | **Consolidated.** `docs/architecture.md` absorbed into this file and removed |
| 2 | ADR-002 Foundation structure | **Ruled.** Module group of seven; no physical Foundation module |
| 2 | ADR-003 Document priority | **Ruled.** Coding Standards and Naming Conventions added to the chain |
| 2 | ADR-005 Money storage | **Ruled.** Integer minor units; DECIMAL for rates and percentages only |
| 2 | ADR-006 Lookup tables | **Ruled.** Notification channels are an enum, not a lookup table |
| 2 | ADR-007 Enum naming | **Ruled.** No `Enum` suffix |
| 2 | ADR-008 API JSON format | **Ruled.** snake_case |
| 2 | ADR-009 API error format | **Ruled.** Single canonical envelope |
| 2 | ADR-010 Service layer | **Ruled.** Presentation may use Eloquent; REST may not |
| 2 | ADR-011 Domain models | **Ruled.** Lightweight helpers permitted; workflows belong to Services |
| 2 | ADR-012 User model | **Ruled.** `first_name`/`last_name`; composite `(type, email)` uniqueness |
| 2 | ADR-013 Roles | **Ruled.** Nine roles; Category Manager retained |
| 2 | ADR-014 Soft delete | **Ruled.** Cascade permitted for dependent child records only |
| 2 | ADR-015 Lookup table status | **Ruled.** `is_active` for lookups, `status` for business entities |
| 2 | ADR-016 Audit columns | **Ruled.** Mandatory for new tables; existing migrate incrementally |
| 2 | ADR-019 Domain layer helpers | **Ruled.** `now()`/`config()` allowed; `cache()`/`request()`/`encrypt()`/`decrypt()` forbidden. Caching and encryption move to Infrastructure |
| 2 | ADR-020 Class size | **Ruled.** 300 lines is a review threshold; method size, constructor arity and complexity stay strict |
| 2 | ADR-021 DTO naming | **Ruled.** `DTO` suffix; `Domain/Data` → `Domain/DTOs` |
| 2 | ADR-022 Documentation naming | **Ruled.** Four categories; existing files not renamed |
| 2 | ADR-023 ORM metadata in Domain | **Ruled.** Casts, observers and global scopes may be named declaratively; services and repositories may not |
| 2 | ADR-024 `auth()` in Domain | **Ruled.** Not added to ADR-019's forbidden list |
| 2 | ADR-025 Out-of-band credentials | **Ruled — revokes Identity Q2.** Reset and verification tokens never appear in an API response; frontend URLs come from config |
| 2 | ADR-026 Shared primitives in Core | **Ruled.** Generic OTP store lives in Core behind a contract; TOTP abstracted; recovery codes configured |
| 2 | ADR-027 Audit is the forensic event store | **Ruled.** Audit records all forensic events, not only model diffs: generic `event_type` + independent `severity` (INFO…CRITICAL); `event`/`auditable` nullable; Audit subscribes to security events across modules. Layering test amended to permit it |
| 3 | ADR-028 Stores created only by admin approval | **Ruled — canonical Organization ↔ Store rule.** Organizations submit Store Opening Requests; admin approves; a Store is created only then (via `StoreOpeningApproved`, owned by the Store module), never automatically. Store limits: override → plan → system default (plans are a lookup table; null = unlimited), enforced authoritatively at approval |
| 3 | Platform `BaseNotification` moved to Core | **Applied.** `App\Core\Application\Notifications\BaseNotification` — platform infrastructure, not Notification-module business logic. All modules depend on the Core base; the module-isolation violation is removed at the root, not excepted |
| 3 | ADR-029 Organization ownership | **Ruled.** Exactly one Owner, always; the Owner cannot be removed, only transferred (atomically) to another active member; an org can never be ownerless. Enforced in schema + action + policy |
| 3 | ADR-030 Organization isolation (multi-tenancy) | **Ruled — platform-wide.** A user may belong to many organizations, each with its own role; all org-scoped data is isolated by `organization_id` and no org sees another's. Binds every future seller-owned module. Supersedes the spec's "a seller owns at most one" |
| 3 | ADR-031 Platform invitation architecture | **Ruled — platform-wide.** Invitations are Core infrastructure (tokenizer contract + `HasInvitationLifecycle` trait + shared `InvitationStatus`), reusable by any module; Organization is the first consumer. Invitations never create users; acceptance requires an authenticated account (register-first otherwise); only the token HASH is stored, never the raw token |
| 4 | ADR-032 Event-driven, idempotent store creation | **Ruled.** A Store is created only by a listener consuming `StoreOpeningApproved` — never by a seller action, never self-created. Creation is idempotent, keyed on `stores.opening_request_uuid` (UNIQUE); replay/redelivery yields one store. The listener emits `StoreCreated` |
| 4 | ADR-033 Cross-context references by id/UUID | **Ruled — platform-wide.** A downstream context references an upstream aggregate by id + UUID (optional DB FK), never importing its models/services/repositories. Store persists only `organization_id`/`organization_uuid`, no `organization()` relation. Cross-context data flows through events or a published Core query contract. Binds Product, Catalog, Offer, Order, Payment |
| 4 | ADR-034 Public storefront is a distinct read surface | **Ruled.** Store splits into a public, unauthenticated, allow-list read surface (slug/path-resolved per ADR-035, live stores only, no internal ids) and a private seller/admin surface (settings, draft state). The platform's first public read boundary — its own controllers, resources and throttle |
| 4 | Core `OrganizationAuthorizationContract` (ADR-033 §9.1 mechanism) | **Ruled.** Cross-context authorization uses a small Core query contract; Organization implements it as the single source of truth for memberships and capabilities. Store depends only on the contract — no replicated read model, no event-sync. The standard mechanism for all future seller-owned modules |
| 4 | Store-management capabilities on frozen Organization | **Ruled (Store-required change).** `OrganizationCapability` gains `StoreManage` (Owner + Manager). No `OrganizationRole::Admin` is introduced; if a future need requires an org Admin tier it comes via a separate ADR, not by expanding the frozen model |
| 4 | ADR-035 Stores addressed by platform path in v1 | **Ruled (scope simplification).** Stores are reached only via `/store/{slug}` (localised `/magaza/{slug}`); no custom domains, subdomains, DNS/TXT verification or host resolution in v1. Removed the `StoreManageDomains` capability + the contract's domain question; the model stays extensible so a future dedicated ADR adds `StoreDomain` + the Owner-only domain capability additively |
| 4 | ADR-036 Public storefront is composed, not owned | **Ruled — platform read contract.** Store owns only the storefront core (identity/branding/SEO/contact/locale); future modules (Product, Category, Campaign, Review, Statistics) enrich it via Core `StorefrontContributorContract` + `StorefrontRegistry`, contributing a section under `extensions[key]` — Store never depends on them. The public resource is a strict allow-list (UUID only, no internal id/settings/audit), extended additively so the contract stays stable |
| 5 | ADR-037 The catalog is shared; the seller↔product link is an Offer | **Ruled — the platform's product model.** A Product is platform-owned and shared: one canonical entry that many sellers sell against, never a per-seller copy. A seller may only *propose* a product (moderated); the seller↔product link is an Offer referencing a Product/Variant by uuid. **A Product carries no price and no stock** — the boundary that lets one product be sold by many sellers. Cost accepted: dedup and moderation become first-class from day one |
| 5 | ADR-038 The taxonomy is central, owned by the Category Manager | **Ruled.** Categories are a platform-owned tree maintained by the Category Manager role (ADR-013 — the role's reserved home). Each category carries an attribute schema (applies / required / variant-defining, per category); products attach to a **leaf** and must satisfy it. Sellers cannot invent categories, attributes or values. Tree storage is an adjacency list + materialised `path`, self-owned, no tree package |
| 5 | ADR-039 Variants are first-class | **Ruled.** A Product has 1..n ProductVariants; the **variant** (not the product) carries the SKU and is the unit Offer, Inventory, cart and order lines reference. A product with no variant axes is one `is_default` variant, never a special case. Cost accepted: a product→variant join on every read and a more complex authoring UI, against rewriting Offer/Inventory/Order later |
| 5 | ADR-040 Catalog references other contexts by id/UUID | **Reaffirms ADR-033** for the first module built after the Org/Store freeze. Catalog imports no module and is imported by none; it holds `proposed_by_org_uuid` for provenance/moderation scoping only, with no Organization relation. Downstream contexts read it through the Core `CatalogQueryContract` and its domain events |
| 5 | ADR-041 Catalog enriches the storefront only once products are sellable | **Ruled.** Catalog registers **no** `StorefrontContributorContract` in Phase 1: a store page shows its Offers, which do not exist yet. The product-listing contributor ships with Offer, which owns the store↔product relationship. Phase 1 touches neither Store nor the storefront and is admin/seller-facing only |
| 6 | ADR-042 An Offer is a priced listing against a variant | **Ruled — the platform's selling model.** An Offer is one seller org's price (+ optional list price) and stock for one `ProductVariant` (ADR-039), by uuid; one product, many offers; at most one active offer per (org, variant). Reaffirms ADR-037 (the seller↔product link is an Offer, never a copy). Cost accepted: no single product price — buyer reads fan a variant out to competing offers and pick a winner at read time |
| 6 | ADR-043 Stock lives on the Offer this sprint | **Ruled.** The Offer carries a simple integer `stock_quantity`; out-of-stock is derived (`= 0`), never stored. Inventory later becomes the authority for on-hand + reservations and the counter migrates to it. Cost accepted: no reservation semantics for one sprint (harmless — nothing checks out yet), and a migration owed to Inventory |
| 6 | ADR-044 Offers are not moderated | **Ruled.** An offer goes live on create/edit (no draft→review); the product was already moderated, price/stock are seller commercial freedom. Validation (price > 0, `list_price ≥ price`, published product, active in-scope store) is not moderation. Admin oversight is reactive suspend/reinstate, the Store/User shape. Cost accepted: an abusive price is visible until reacted to; per-offer moderation would not scale |
| 6 | ADR-045 The buy box is computed, never stored | **Ruled.** No persisted winner and no ranking job: the featured offer is the cheapest `Active`, in-stock offer, ties by earliest `created_at`, computed at read time; paused/out-of-stock/suspended excluded, withdrawn never appears. Cost accepted: every product-page read recomputes, against the cache-coherency cost of invalidating a stored winner on every competing offer's change |
| 6 | ADR-046 Offer ships the storefront product-listing contributor | **Fulfils ADR-041.** Offer registers the `StorefrontContributorContract` (ADR-036) surfacing a store's active offers; Store depends on Offer for nothing. Offer imports no module — reads Catalog via `CatalogQueryContract` + a new `CatalogBrowseContract` (the one sanctioned Catalog change, read-only over its search index, for the seller "select a product" flow), org via `organizationIdsForUser()`, store via `StoreQueryContract`; exposes Core `OfferQueryContract`. All cross-context refs are id + uuid (ADR-040/033) |
| 7 | ADR-047 Product attachment uses an `accepts_products` flag | **Amends ADR-038 (owner-approved, live-test refinement).** A product attaches to any category the **Category Manager** flags `accepts_products`, not to tree leaves; a flagged category may still have children (a product can sit at *Makyaj* while *Göz Makyajı* exists under it). Migration: current leaves → true, non-leaves → false. Cost: the "children ⇒ no products" invariant is replaced by a manual, more expressive flag |
| 7 | Catalog live-test fixes (not frozen) | **Owner-approved.** Brand create/edit gains the logo upload field it was missing; empty categories (no products **and** no children) become deletable; the seller offers list shows buy-box rank + winning price (Offer, computed per ADR-045). Commission stays deferred to Payment/Finance (ADR-042 §0.2) — not shown, no source of truth yet |
| 7 | Frozen **Organization** — owner-approved refinements | **Owner-approved exceptions to the freeze (Presentation + one action), Domain otherwise untouched.** (a) Team member management gains **edit role** (existing `ChangeMemberRoleAction`) and **deactivate/reactivate** (new `ChangeMemberStatusAction` over the existing `status`), keeping **remove**. (b) Seller onboarding reflow: the "Yeni Organizasyon" form collects **required store info** and creates the org + its Store Opening Request in one step (reusing `CreateStoreOpeningRequestAction`); the standalone SOR create page leaves the nav. **ADR-028 preserved** — a store is still created only by `StoreOpeningApproved` |
| 7 | Frozen **Store** — owner-approved refinements | **Owner-approved exceptions to the freeze.** (a) **Store name is unique platform-wide** — validated at request time (a new `StoreQueryContract` read) and enforced by a DB unique index. (b) Sellers request an additional store from the **"Mağazalarım"** page ("Yeni Mağaza Talep Et"), the relocated SOR entry point. Creation path unchanged (ADR-028/032) |
| 8 | ADR-048 Inventory is the availability authority | **Refines ADR-043.** Inventory owns on-hand + reserved per (seller org, variant); `available = on_hand − reserved` is what the buy box reads via the Core `InventoryQueryContract`. The seller keeps entering stock on the Offer form; Inventory mirrors on-hand by subscribing to Offer stock events **by class-string** (no import). Cost: on-hand lives in two places, kept consistent by a synchronous event, rebuildable from the Offer |
| 8 | ADR-049 Reservation primitives as a Core command contract | **Ruled.** `reserve/release/commit` ship as a Core `InventoryReservationContract` before Order (Order is the first later caller); `reserve` only when `available ≥ qty`, `commit` lowers on-hand+reserved, idempotent on the reference. Exercised by tests this sprint. Cost: machinery with no live caller for one sprint — the point of phasing the authority ahead of its consumer |
| 8 | ADR-050 Append-only movement ledger is the source of truth | **Ruled.** Every change is an append-only `StockMovement` (signed on_hand/reserved deltas + type + reference); on_hand/reserved are projections rebuildable from it; never updated/deleted (non-negotiable #9 applied to stock). Cost: two writes per change + unbounded ledger, for auditability and reservation clarity |
| 8 | ADR-051 Single stock pool per (org, variant) in v1 | **Ruled.** No warehouse/location dimension; multi-warehouse returns additively (a location on the record + movement) without reshaping the reservation contract. Cost: real multi-warehouse sellers cannot model locations yet — untestable with no Order/Shipping consumer |
| 9 | ADR-052 Multi-seller cart; checkout splits into one order per seller | **Ruled.** One customer, one cart, items from many sellers; checkout partitions by selling org into one `Order` each under a `checkout_group_uuid`. Each seller manages only their own order. Cost: no single customer-order total — a purchase is N orders grouped for display and reconciled by a future Payment against one charge |
| 9 | ADR-053 Order lines are immutable snapshots | **Ruled.** An `OrderLine` freezes unit price, product title, variant label and tax rate at placement (addresses too, ADR-056); upstream changes never touch a placed order. Cost: Order duplicates Catalog/Offer data and upstream corrections don't reflect on past orders — the point of a financial record |
| 9 | ADR-054 Checkout reserves stock; placement commits (two-step via Inventory) | **Ruled.** Order is the first real caller of `InventoryReservationContract`: checkout reserves (keyed on order uuid), placement commits, cancel/expiry releases. Until Payment, placement commits; Payment later moves commit to payment-success. Cost: stock leaves on placement with no money taken — mitigated by a 30-min reservation-expiry sweep |
| 9 | ADR-055 Order computes tax from the product's bracket, never commission | **Ruled.** Each line extracts KDV from its tax-included price (ADR-042) using the product's tax rate; Order produces the invoice tax breakdown but not commission/payout (Payment/Finance). Cost: tax logic lives in Order before an invoicing module; commission has no source of truth yet |
| 9 | ADR-056 Customer address book; separate shipping & billing; product gains a tax bracket | **Ruled.** Order owns `CustomerAddress` (many per customer, shipping/billing defaults); checkout picks separate shipping + billing, both snapshotted; authenticated customers only. **Catalog addition (not frozen):** a managed `tax_rates` lookup + `Product.tax_rate_id` chosen at authoring & moderated — a product *classification*, not a commercial term, so ADR-037's no-price/no-stock boundary holds. Cost: the sprint widens beyond the order aggregate; Catalog gains a field + table for Order |
| 9 | ADR-057 Placement holds the reservation; cancellation is actor-typed | **Amends ADR-054 (owner-approved, closes Order follow-up #1).** Placement no longer commits — it holds the reservation (`AwaitingPayment`); **commit is deferred to Payment**. Cancellation is typed: **buyer** → release (return); **seller** (cannot fulfil) → release **+ zero the seller's on-hand** (warned) via an `OrderCancelledBySeller` event the Offer consumes by class-string, flowing through the Offer→Inventory mirror; **admin** → release by default (zero optional); **system/expiry** → release. Expiry sweeps only un-placed `Pending` checkouts. Cost: on-hand doesn't decrement until Payment; post-payment returns/RMA (needing an Inventory restock primitive) stay out of scope |
| 10 | ADR-049 amended — the reservation reference is the caller's own STRING key | **Ruled (owner-approved, 2026-07-31).** `InventoryReservationContract::reserve/release/commit` take `$reference: string`, and `stock_reservations.reference_uuid` becomes `reference` (string, still UNIQUE). ADR-049 assumed a caller would key a hold on a uuid it already had; ADR-057 made Order's key per LINE (`{order_uuid}:{variant_uuid}`) because a reservation is unique per reference and two lines sharing one leaves the second unheld. That composite is not a uuid, so **every checkout 500'd on PostgreSQL while the SQLite suite stayed green** — the same driver blind spot Inventory §12.3 records for its CHECK constraint. The column now matches what the contract always claimed: the key is the caller's, in the caller's format, stored and not interpreted. Readable by request rather than by rule — the ledger settles disputes, and `{order}:{variant}` answers what a hashed uuid could not. Cost: nothing enforces a key scheme across callers; the unique index makes a collision loud rather than silent. Guarded by a pgsql-backed checkout test |
| 10 | ADR-058 Customer storefront is a separate Next.js app; the buyer read is composed | **Ruled (owner-approved).** A separate Next.js app in a monorepo `storefront/` folder on the **same origin** as the API (Sanctum SPA cookie auth, no CORS). The buyer read is composed: **Catalog owns product content** (public detail + browse/search), **Offer owns price/availability** (buy box + batch prices), only **sellable** products listed via `OfferQueryContract`. Phase A public read surfaces (backend) → Phase B the Next.js app → Phase C deploy (Node systemd + nginx same-origin). Checkout stops at awaiting-payment. Cost: a second runtime + content/price composition per listing item |
| 6 | `StoreQueryContract::liveStoresForOrganization()` on frozen Store | **Ruled (Offer-required change).** Every existing method on that contract walks store → org, which is all an isolation check needs. Offer asks from the other end — "may this company sell at all, and under which storefront?" (Offer.md §3.4) — with no store uuid yet to ask about, so the precondition was unanswerable and the seller's offer form had nothing to attribute a listing to. One additive read method returning `uuid => display name`: the name is carried because a picker built from uuids alone would ask a seller to choose between two identifiers, and one method answering both the precondition (`=== []`) and the picker beats two that overlap. No models and no internal store ids, so the boundary is unchanged and Store gains no dependency. Same shape and justification as the `StoreManage` capability frozen Organization gained for Store |
| 8 | Offer stock events carry the selling org's id **and** uuid; the buy box asks Inventory | **Ruled (Inventory-required changes to Offer — complete but NOT frozen, its §14 anticipated them; both confirmed at approval, Inventory.md §10.4/§10.5).** (a) `OfferCreated`, `OfferStockChanged` and `OfferWithdrawn` gain `sellingOrgId` + `sellingOrgUuid`. Inventory consumes these blind, by class-string, and a stock pool is keyed on the ADR-040 id/uuid pair — with only one half on the payload the mirror would have to look the company up in a module it may not import. (b) `OfferQuery::eligible()` drops the SQL `stock_quantity > 0` filter and asks `InventoryQueryContract::isAvailable()` per candidate instead. Functionally identical this sprint (`reserved` is always 0 with no Order), so a parity test pins the old behaviour — but it makes expressible the case no column on the Offer could state: every unit held for somebody else's checkout. Cost: the partial index `offers_buy_box` is predicated on `stock_quantity > 0` and no longer matches, and availability is read per row rather than joined — both recorded as Inventory.md §12.5 follow-ups. (c) `OfferFactory` dispatches `OfferCreated` after creating, so factory-made offers get a real pool through the real mirror rather than a test-only shortcut |
| 9 | Catalog gains `tax_rates` + `Product.tax_rate_id`; Offer gains `activeOfferByUuid()` | **Ruled (Order-required changes; both modules complete but NOT frozen).** (a) A managed `tax_rates` lookup and `Product.tax_rate_id`, chosen at authoring and moderated, with `CatalogQueryContract::taxRateForProduct()` returning a decimal-string ratio — ADR-056's sanctioned change. A KDV bracket is a **classification of the goods** (a book is %1 whoever sells it), not a commercial term, so ADR-037's no-price/no-stock boundary holds; `CatalogBoundaryTest` exempts the column BY EXACT NAME while keeping the `tax` fragment, so `tax_total` or `unit_tax_minor` on a product would still fail the build. (b) `OfferQueryContract::activeOfferByUuid()`: every other method answers a LIST question because until Order existed every caller held a product or a store, and a cart line holds an offer. It reuses the buy box's own `eligible()` path so "can this go in a basket" and "is this what a product page features" cannot drift. Cost: Catalog carries a table and a field for Order's sake, and the products backfill runs in a seeder because lookup rows are operator-owned |
| 9 | `MoneyString` promoted from Offer to Core | **Applied.** Integer minor units → the decimal string an API returns (005 §28). It lived in `Offer\Presentation\Support` with a docblock naming the condition that would move it — a second module needing it. Order renders order lines and totals and may not import Offer (`LayeringTest`), so the choice was Core or a copy; a second money formatter is the duplication that ends with two endpoints disagreeing about a kuruş. It waited for the second caller rather than being promoted on principle |
| 9 | Order's reservation reference is per LINE, not per order | **Applied (deviation from Order.md §3.1/§3.2, reported).** The spec writes `reserve(…, orderUuid)` and `commit(orderUuid)`. An Inventory reservation is one row on a UNIQUE reference and reserving is idempotent on it, so an order with two lines sharing one reference would silently leave the second unheld — the worst failure this integration can have, and invisible from Order's side. The reference is `{order_uuid}:{variant_uuid}`, rebuilt at commit and release rather than stored, and unique because a seller holds at most one active offer per variant (ADR-042 §3.2). ADR-054 is unchanged: checkout reserves, placement commits, keyed on the order |
| 9 | Cancelling a PLACED order does not restock | **Applied (consequence of §3.3 as written, reported).** For a committed reservation Inventory's `release()` is a documented no-op, so the order moves to `Cancelled` and the units stay out. Inventory has no un-commit primitive and must not grow one by side effect — reversing a sale is a different business event from abandoning a hold, and conflating them in the append-only ledger (ADR-050) makes "why did my stock go up" unanswerable. Recorded as Order.md §12.5 follow-up #1, needing its own ruling before Payment ships |
| 8 | The `reserved <= on_hand` CHECK is PostgreSQL-only | **Applied (deviation from Inventory.md §3.4, reported).** SQLite cannot `ALTER TABLE … ADD CONSTRAINT` and the suite runs on SQLite `:memory:`, so the constraint is added on pgsql only. The invariant is enforced where it is actually decided — inside the reservation actions, under a row lock — and the constraint is the production backstop against a future writer that forgets. Cost accepted: the suite cannot prove the database refuses a bad row, so that one test is `->skip()` on non-pgsql with the reason stated, alongside a driver-independent test that the READ clamps a nonsensical projection. `StockItem::available()` returns no negative on any driver |
| 6 | `CatalogBrowseContract` reads the database, not the search index | **Applied (deviation from Offer.md §8.2, reported).** The spec describes the seller's "select a product to sell" browse as reading Catalog's OpenSearch index. It reads Postgres instead: that index is tuned for BUYER relevance (Turkish analysis, boosts, facets — `docs/search.md`), whereas this is a seller filtering by category and brand for a product they already hold; and index-backed would put a cluster on an internal panel's critical path — a seller blocked from listing because OpenSearch is down, and a flow the suite (`SCOUT_DRIVER=null`) could never exercise. Cost accepted: `LIKE` matching, not relevance-ranked, and it will not scale to millions of rows. It sits behind the contract precisely so swapping to an index-backed implementation is one container binding. Buyer-facing search still goes to the index (Offer.md §10) |
| 10 | `StoreQueryContract::publicProfilesFor()` on frozen Store | **Ruled (Offer-required change, owner-approved 2026-08-01).** The second change frozen Store has granted a later module, on the same footing as `liveStoresForOrganization()` above. Every other method on the contract answers a question about a store's **state** — exists, is live, who owns it — because that is all an isolation check needs. Offer's buy box arrived with a different question: it holds store uuids and has to put a merchant's **name** in front of a shopper, so without this the seller row renders "Satıcı: a1086566-10aa-…". Batched (`uuid => name + city`), because a product page asks about every seller of one product. **Live stores only** — this is the one method here whose output reaches a public payload verbatim, so a suspended shop cannot be named by a caller that forgot to filter. No models, no internal ids, no dependency. Cost: `city` reads from the free-form `store_contacts.address` jsonb and is **null on every store today**, because no seller-facing form writes one — the read ships now so the payload shape does not change again the day that form does |
| 10 | The public product page shows the **GTIN**; the listing still does not | **Ruled (owner-approved 2026-08-01, reverses an implementation-level choice).** The barcode was withheld from every public surface as "a competitor's shortcut to matching inventory". True of the number, not of the endpoint: it is **printed on the box** the shopper is holding, so the secrecy protected nothing while costing the buyer the ability to confirm the item (the design's "Barkod" row). Detail surface only, and the asymmetry is the decision: one product's barcode is a fact about that product, while every product's barcode — paginated, anonymous, throttled — is a catalogue export with a stable matching key. Two tests asserted the old rule and now assert the split in one place, so a rule spread across two resources cannot be re-unified by accident. Cost: nothing on this platform now prevents patient enumeration of GTINs one product page at a time — accepted, since that is the pace at which the number is already public |
| 10 | Listing cards get `seller_count` + `list_price`; the buy box gets a named `store` | **Applied (storefront data gaps, Catalog/Offer not frozen).** `POST /offers/prices` gains both fields on the existing payload rather than a second endpoint — a card needing three round trips renders three times. `seller_count` counts **distinct merchants, not offers**: an offer is per variant (ADR-042/039), so one seller listing three sizes is one choice, and "3 satıcı" over one merchant is a lie the shopper finds on the next page. `list_price` is the **winning** offer's, because a shared catalogue has no product-level "was" price (ADR-037). **No discount percentage** — both inputs are declared facts, the percentage is a claim computed from them, and computing it server-side would make the endpoint the authority on how it rounds. The batch surface still names **no seller** (a count identifies nobody); the product page's `store_id` became `store: {id, name, city}`, which removed nothing — the uuid gained a name to stand next to |
| 10 | ADR-056 amended — an optional `neighborhood`, and a geo reference dataset in Localization | **Ruled (owner-approved 2026-08-03).** The customer address gains a nullable, free-text **`neighborhood`** (mahalle), snapshotted onto the order with the rest (ADR-053). Three lookup tables — `geo_provinces` → `geo_districts` → `geo_neighborhoods`, `is_active` on each (ADR-015) — land in **Localization**, the one module everything may read, so no new cross-module import appears; TR is seeded (81 / 973 / 73,300) from an MIT dataset committed **gzipped** rather than fetched at seed time, because a deploy that depends on a third-party host still serving the same shape is the failure nobody can diagnose at 2am. **The address holds no foreign key into these tables and they validate nothing** — that is the load-bearing sentence: a mahalle is renamed or merged by administrative act several times a year, and an address saved before that must not become invalid or unreadable because the registry moved on. The same reasoning that keeps `city` a string, and what lets every non-TR address keep sending free text or nothing. Cost: a TR-specific dataset to keep current plus a read surface to serve it; mahalle is ~73k rows, which is why the level could not be bundled into the client the way il and ilçe were |
| 10 | A non-uuid string reached a `uuid` column again — the geo cascade | **Applied (caught live, guarded).** `GeoRepository` resolves a parent by NAME (a saved address holds "İstanbul", not a uuid) and first asked `where('uuid', $v)->orWhere('name', $v)`. On PostgreSQL that is `SQLSTATE[22P02]`, a 500 on the most ordinary call the endpoint has; on SQLite the column is text and the comparison quietly returns false, so the whole suite would have stayed green. **This is the second occurrence of the class** — ADR-049's reservation reference was the first, and broke every checkout in production. The uuid comparison is now guarded by `Str::isUuid()`, and `tests/Integration` (the pgsql suite that first bug produced) gained a geo case. The standing rule this establishes: **any read that accepts user text and touches a `uuid` column gets a case in that file** |

| 10 | ADR-059 Flat root-level slugs are the public storefront address; a global slug registry | **Ruled (owner-approved 2026-08-03).** Product, category and brand are addressed at the ROOT with no type prefix (`/bioderma`, `/cilt-bakimi`, `/avene-...-krem`). A prefix is SEO-NEUTRAL — Google ignores it — so this is an aesthetic choice whose real cost is a **shared namespace**: those three plus the storefront's own pages compete for one set of names. A `slugs` registry owns every public slug with ONE global unique index and a reserved-word list; a slug is Turkish-folded, suffixed on collision, and **stable once issued** — a rename does not move a URL, and a deliberate change keeps the old row as a non-canonical **alias** so the resolver reports the new address and the storefront 301s. `GET /resolve/{slug}` returns kind + uuid + canonical slug, which is what lets one catch-all route render three page types without three speculative requests per paint. New surfaces: `/categories`, `/categories/{slug}`, `/brands`, `/brands/{slug}`, all with **sellable** counts so a menu and the listing it opens cannot disagree. Cost: a registry table, a backfill migration, an alias trail that only grows, a resolver call on every page load — and **a new storefront route must be added to the backend's reserved list BEFORE the frontend ships it**, or a product silently occupies that address |
| 10 | A non-uuid string reached a `uuid` column a THIRD time — and the rule that ends it | **Applied (live 500s, guarded).** `?category=Dermokozmetik` and `/products/{slug}` both 500'd in production: `where('uuid', <a word>)` is `SQLSTATE[22P02]` on PostgreSQL and a silent false on SQLite, so the suite stayed green through both. Occurrence one was ADR-049's reservation reference (every checkout, in production); two was the ADR-056 geo cascade (caught by hand); three is this. **The rule is now an ADR-059 clause rather than a comment: every public lookup resolves BY SHAPE and 404s on a miss, and a value that is not uuid-shaped never touches a uuid column.** `App\Shared\Support\PublicKey` is the single decision point — in `app/Shared` because Catalog's Infrastructure query needs it as much as its controllers, and Infrastructure may not import Presentation. Every read that takes user text near a uuid column now has a case in `tests/Integration`, the PostgreSQL suite the FIRST occurrence produced |

| 10 | `make lint` has never passed; the toolchain is unpinned | **Diagnosed 2026-08-04, deliberately NOT fixed inside a feature branch.** `analyse` and `test` are enforced and green; `lint` fails on ~405 files. Measured rather than assumed: the INITIAL commit (`82b6963`), checked out and linted with the OLDEST Pint the constraint allows (`1.18.3`, floor of `^1.18`) against the repo's own `pint.json`, already fails **150 of 410** `app/` files — so this is not drift from any recent update. `composer.json` is unchanged since that commit and **`composer.lock` is not tracked**, so every environment resolves its own Pint and gets different opinions on `phpdoc_align`, `ordered_imports`, `fully_qualified_strict_types`, `braces_position`. One rule is a flat contradiction: `pint.json` sets `not_operator_with_successor_space: false` (asking for `!$foo`) while the whole codebase writes `! $foo`; flipping it alone removes 70 files' worth of failures. Remedy, as its own commit: commit a lock + pin Pint, settle that rule, then one formatting-only `make lint-fix`. Cost of NOT doing it now: `make check` is red on its first stage, so the honest gate for a feature branch is analyse + test + "no fixer class this branch did not already inherit" |

| 10 | A non-uuid string reached a uuid column a FOURTH time — `/products/{slug}/offers` | **Applied (live 500, guarded).** ADR-059 guarded Catalog's own reads and missed the buy box, which is a different module: Offer took the URL segment straight into `activeOffersForProduct()`, so the storefront's product page 500'd on the flat slug it was built to use. The fix could not reuse `PublicKey` alone — the slug registry is Catalog's and **Offer imports nothing** — so `CatalogBrowseContract` gains `publishedProductUuidFor(string $idOrSlug): ?string`, resolved by shape on the Catalog side and returning null on a miss. Offer asks the same port it already asks for a product's title, and no new import appears. Aliases resolve too, so an inbound link to a retired address still shows a buy box while the storefront works out its 301. Guarded in `tests/Integration` like the three before it. The lesson the fourth occurrence adds: **the rule has to travel with the URL, not with the module** — a guard applied per-module leaves the next consumer of the same public identifier exposed |

| 11 | ADR-060 Payment settlement & PSP | **Ruled.** Single-merchant settlement with manual/batch payout (sellers are not submerchants — the platform holds and later pays out; BDDK licensing is the accepted early-phase cost, submerchant migration is a future ADR). PayTR is the first and only-visible gateway behind Core's `PaymentGatewayContract`, integrated iFrame-shaped so no card data touches the platform. One Payment per Order `checkout_group` (`merchant_oid = payment.uuid`); the hash-verified, idempotent server-to-server callback is the source of truth, not the browser redirect. **Closes ADR-054/057:** on the success callback Payment drives Inventory's reservation commit via the Core command port, keyed by the `order_uuid:variant_uuid` string reference. Imports no module |

| 11 | ADR-061 Commission engine | **Ruled.** Commission is a multi-dimensional rule table (`commission_rules`), scoped optionally by product/brand/category/seller_org (any null = wildcard) with a `DECIMAL` rate and integer `priority`; resolution is most-specific-wins (specificity, then priority, then recency), default = the all-null rule. **Base is the KDV-inclusive sale amount** (owner choice 2026-08-04), integer kuruş. Snapshotted onto order lines at payment (ADR-053 discipline) — a rule change re-prices the next sale, never a settled one |

| 11 | ADR-062 Seller ledger & payout | **Ruled.** Seller balance is an append-only `seller_ledger_entries` ledger (refuses update/delete), typed signed integer-kuruş entries (`sale_credit`, `commission_debit`, `payout_debit`, `refund_debit`, `refund_commission_credit`); balance = Σ entries, computed on read, never stored. A paid order credits the seller net of commission. Payout is admin-created, capped at the computed balance, guarded against going negative, and only **records** the external bank-transfer reference — the software moves no money. Refund reverses through the ledger + Inventory restock; because balance is a sum, refund-after-payout safely blocks the next payout |

| 11 | Payment P1 — the collection core; `PaymentGatewayContract` lives in the MODULE, not Core | **Applied (ADR-060, Payment.md §3/§5) with one reported deviation.** P1 ships the Payment aggregate (one per checkout group — the mirror of ADR-052's split, since a card is charged once), the PayTR iFrame adapter, and a hash-verified idempotent callback that **commits the stock placement only held — closing ADR-054/057's promise**. **The deviation:** the spec placed the gateway port in `app/Core`, and it cannot go there — its signatures are Payment's own DTOs and `LayeringTest` enforces "Core never depends on a module", a rule that outranks a module spec in the document chain. It lives in `Payment\Domain\Contracts` instead; the spec's stated reason ("the domain never names PayTR") is untouched, because the Core placement was never load-bearing — every other Core contract exists so one MODULE can ask ANOTHER a question, while this port points *out of the platform*. Payment.md §3 carries the amendment; **needs the owner's ratification** |
| 11 | `OrderStatus` gains `Paid`; Order confirms itself on a class-string event | **Applied.** The case ADR-057's own docblock predicted, added when the module that can set it arrived. `holdsReservation()` narrowed to exclude it (a paid order's units are committed, not held, and `release()` on a committed reference is a documented no-op) and `isCancellableByCustomer()` narrowed with it — walking away after money changed hands is a REFUND, a different operation with a PSP call behind it. **Payment does not set this status:** it commits the stock (ADR-057 named it the caller) and announces `PaymentSucceeded`; ORDER subscribes by class-string and moves its own state machine. A module setting another's status is the boundary failing at the point where cutting the corner is most tempting |
| 11 | `OrderQueryContract` gains three reads for Payment | **Applied (Payment.md §12, Order not frozen).** `checkoutGroupCustomer()` (whose money it is, ADR-040 pair), `orderLines()` (the PSP basket now, the commission resolver in P2 — SNAPSHOT values, so a re-categorised product cannot move a settled commission), and `reservationReferencesFor()`. The last one's shape is the decision: Payment must commit what placement held, but the key's FORMAT is Order's (`{order_uuid}:{variant_uuid}`, the ADR-049 amendment), so the port hands back assembled references rather than letting Payment reconstruct a string it does not own |

| 11 | Payment P2 — the commission engine; `order_lines` gains a two-part snapshot | **Applied (ADR-061, Payment.md §6).** `commission_rules` with four nullable scopes — null = wildcard, all-null = the platform default, which falls OUT of the resolution rule rather than being a case in it. **Specificity outranks `priority`, always**: a priority that could beat it makes "why did this line get 12%?" unanswerable without simulating the engine, the failure mode of every priority-ordered rule system. A category rule covers its **subtree**, matched against the line's SNAPSHOTTED ancestry so a re-categorised product cannot move a settled commission. Base is the **KDV-INCLUSIVE** line total (owner choice); one half-up rounding helper serves charge and refund, because a kuruş of disagreement between them would drift a seller's balance forever. **Two snapshots at two moments:** classification at checkout (what the rules match), commission at payment (what they came to) — a rate edited before anyone paid should apply, one edited after must not. **The spec's §12 claim that the line already carried brand/category was false**; the three columns were added, Order-owned, the ADR-055 shape. Payment computes through a Core `CommissionQueryContract`, ORDER writes its own table |
| 11 | `OrderLine` immutability gains exactly one hole, and it is narrower than it looks | **Applied.** ADR-053 makes a placed line immutable; ADR-061 needs the commission written at payment, which is later. The `updating` guard now permits a change that touches ONLY the three commission columns AND only while `commission_resolved_at` is null. That phrasing is deliberate: it enforces the ADR's own sentence — *a commission a seller has already seen deducted never moves* — so a retried callback, a later rule change and a direct `update()` are all refused. Touching any other column fails the whole write, so adding one more key to the same call cannot turn the exception into a general escape hatch |

| 11 | Payment P3 — the seller balance is a LEDGER, and there is no balance column anywhere | **Applied (ADR-062, Payment.md §7).** `seller_ledger_entries`, append-only with both hooks refusing (non-negotiable #9). A paid order appends TWO rows per seller — `sale_credit` of the KDV-inclusive total and `commission_debit` of the frozen commission — because "you earned 120,00 and we took 21,60" is a sentence a merchant can check while "you earned 98,40" is one they can only accept. **The sign lives on the TYPE**: credits store positive and debits negative, so a balance is a plain `SUM()` and no call site can append a positive commission and pay the seller the platform's cut. **The ledger reads the frozen commission rather than resolving the rules again** — two computations of one number is how they stop agreeing, and a kuruş of drift per order is unreconcilable a year later. That makes it depend on Order's listener having run first (provider boot order); it does not trust that — a null commission makes it SKIP AND LOG, because overpaying a merchant is discovered at payout while a missing entry is recoverable. Idempotent twice: the listener skips what exists, and `(payment_uuid, order_uuid, type)` is UNIQUE so a race loses. Runs after commit, so it cannot record money a rollback took back |
| 11 | `SellerLedgerEntry` gets NO escape hatch, unlike `OrderLine` | **Applied.** P2 gave the order line one narrow, once-only hole because its commission is genuinely decided later. This model gets none: every field of a ledger row is known the moment it is written, so an edit could only be a correction — and a correction to money is a NEW ENTRY, which is what keeps the sum trustworthy and a dispute answerable six months on. All five entry types are declared now (three unwritten until P4/P5) because what is being defined is the SIGN CONVENTION, and a convention has to be complete to be coherent |

| 11 | Payment P4 — payouts are RECORDED, never performed; a sixth ledger type is required | **Applied (ADR-062 §8) with one reported extension.** The software moves no money: a `Payout` row says an admin decided to send a seller their balance, and later that a human or bank did and here is the reference. **The `payout_debit` lands at CREATION, not at `paid`** — otherwise two admins could each create a payout for the whole balance, both pass their own check, and the seller be overdrawn when both transfers went through. **That forces a sixth `LedgerEntryType`, `payout_reversal_credit`, which ADR-062 does not list — reported for ratification:** the ledger is append-only so a rejected transfer's debit cannot be deleted, and none of the five existing types means "that payout did not happen". The concurrency guard is a row lock on the seller's own ledger taken before the balance is read — a `SUM` cannot be locked but the rows it sums can; its limit (a seller with no rows has nothing to lock, and also a zero balance) is stated rather than hidden. A payout is append-only in its MONEY and a state machine in its OUTCOME: the guard permits only the six outcome fields and only out of `pending`, the same narrow-hole shape `OrderLine` uses. Never deleted — a mistaken payout is marked failed, which reverses the debit and keeps both facts |
| 11 | A cast enum read back through `getOriginal()` — the same shape as `OrderQuery::orderStatus()` | **Caught by tests.** `Payout::isSettling()` compared `getOriginal('status')` to `PayoutStatus::Pending->value`; because `status` is cast, the accessor returns the ENUM and the comparison was always false — which silently refused every legitimate settlement and would have left payouts stuck `pending` forever. Identical in shape to the bug `OrderQuery::orderStatus()` hit when it returned a cast enum where a string was expected. Worth recording as a recurring trap: **a cast attribute read through a raw-looking accessor comes back cast**, and the failure is silent in both directions |

| 11 | Payment P5 — the refund; the Core command port gains a FOURTH verb | **Applied (ADR-062 §8, amends ADR-049).** The one operation on this platform that moves real money OUT — a payout only records a transfer a human made, the callback only records what a buyer did. **The PSP goes first:** nothing is written until PayTR agrees, because writing the ledger first would leave a seller debited for a refund that never happened and, unlike a payment, no callback is coming later to correct it. Then per refunded order: `refund_debit` (the KDV-inclusive total) AND `refund_commission_credit` (the frozen commission) — anything less than both means the platform keeps its cut and the SELLER pays for the buyer's return. **`InventoryReservationContract` gains `restock()`**, which raises `on_hand` and leaves `reserved` alone (the hold ended when the sale completed and does not come back) and is a no-op on anything not `committed` — the guarantee that stops a retried refund inventing stock that does not exist. It is deliberately not `release` called late: Order.md §12.5 ruled that reversing a sale and abandoning a hold are different business events, so it carries its own movement type, terminal reservation state and timestamp. **This closes Order.md §12.5 follow-up #1** |
| 11 | A refund names ORDERS, not an amount; `payment_refunds` is one row per (payment, order) | **Applied.** "Partially refunded" on this platform means some of the SELLERS' ORDERS in the basket — the ADR-052 split seen from the refund side. An arbitrary lira figure could not say which seller it came out of, which commission to give back, or which units to restock. What is refunded is **Σ of the refund rows**, never a column — the same rule as the seller balance, for the same reason. The unique `(payment_id, order_uuid)` index is the real guard: a refund is the one operation here a human triggers by clicking, so it WILL be clicked twice, and there is no PSP retry semantics to lean on |
| 11 | `OrderStatus` gains `Refunded`; `Paid` stops being terminal | **Applied.** The case the `Paid` docblock reserved for P5, added by exactly that phase. `isTerminal()` now names `Cancelled`/`Refunded` — a refund moves a paid order AND its stock. `isCancellableByCustomer()` stops delegating to `isTerminal()` and names the two live states instead: cancellation and stock are two different questions, and one method answering both was only correct while the answers coincided. Payment does not set this status either — it announces `PaymentRefunded` with the orders it covered, and Order moves its own machine by class-string |
| 11 | Refund is ADMIN-ONLY in v1 — a stated narrowing of Payment.md §8 | **Applied, reported.** The spec allows "admin, or a customer-cancel that the policy allows". The second half cannot be evaluated yet: whether a customer may reverse their own purchase depends on whether it has SHIPPED, and there is no fulfilment state on this platform — Shipping does not exist. A self-serve refund button that cannot tell "cancel before dispatch" from "return after delivery" would be granting a business rule nobody wrote down. `RefundPaymentAction` takes an actor id and does not care what type of user it is, so when Shipping ships, only `PaymentPolicy::refund()` changes |

| 11 | PayTR refuses a uuid as `merchant_oid`; every live get-token call was rejected | **Fixed (2026-08-05), and the test that missed it fixed with it.** The real API answers `merchant_oid alfanumerik olmalidir, ozel karakter iceremez` — a uuid's hyphens are special characters. The P1 suite stayed green because it mocked PayTR, and its fixture was the string `'a-payment-uuid'`, which is neither a uuid nor alphanumeric and therefore proved nothing about either; the fixture is now a real uuid and the assertion is the hyphen-free form. **The decision is unchanged — one identifier, ours:** the 32 hex digits are the same uuid, losslessly and reversibly, so no second `merchant_oid` column exists to disagree with `payments.uuid`. The strip and its inverse live in `PayTrGateway` alone, because that is the only class allowed to know PayTR exists, and an unrecognised oid passes through untouched so a payment created before the fix still resolves its callback |
| 11 | A non-reportable exception is an exception nobody can read | **Fixed (2026-08-05).** Every PSP refusal here is a domain exception with `$reportable = false` — right, because a declined basket is not an incident — but the consequence was that a live merchant-configuration failure produced a 422 to the buyer and NO record anywhere of why. The platform was taking no money and the diagnosis existed only in a response nobody kept. `PayTrGateway::logRejection()` now writes PayTR's own `reason` to the `errors` channel beside the request fields that decide whether the hash could have matched — **never `merchant_key` or `merchant_salt`**, which would let a log reader forge a "payment succeeded". The exception carries it too, in two DIFFERENT strings: `getMessage()` holds the provider's verbatim words for the stack trace, `PaymentException::userMessage()` resolves the buyer's translation from the context `reason`. Worth generalising: **"expected failure" must not mean "silent failure"** — a refusal the operator cannot read is a refusal they cannot fix |

| 11 | The PSP callback is CSRF-exempt, and the test that would have caught it could not exist as a feature test | **Fixed (2026-08-05).** A PayTR callback posts server-to-server with no browser, no session and no token, and PayTR retries until it is answered `"OK"` — a 419 there means money collected and an order never confirmed, which nothing later repairs. The route is now in `validateCsrfTokens(except:)`. It is not a hole: the endpoint authenticates the SENDER by recomputing the PSP's HMAC with the merchant key, stronger than a token a cookie-bearing browser would supply anyway. **What made this subtle:** Sanctum only promotes a request to the session stack when its Origin/Referer names a stateful domain, so PayTR was never actually blocked — a same-origin browser POST was, which is what made it look like the cause. Settlement must not depend on a header a third party controls. **And the test:** `ValidateCsrfToken::handle()` short-circuits on `runningUnitTests()`, so a feature test posting to this route passes CSRF unconditionally and proves nothing — the new test drives the middleware directly with that one override removed, and also pins that the except entry still matches the registered route URI |
| 11 | PayTR learns our callback address from ITS panel — and it was pointing at a dead path | **Diagnosed (2026-08-05), owner action.** The iFrame API has no notification-URL parameter (`merchant_ok_url`/`merchant_fail_url` only redirect the browser), so the address is a merchant-panel setting. It was set to a legacy `/ajax.php?page=paytr`: 670 POSTs from PayTR's own IP in two hours, every one a 404, every one retried because it never heard `"OK"`. **The failure is invisible from the application** — nothing is logged here because nothing arrives here — so the evidence only exists in the nginx access log. Recorded three ways so it cannot be silent again: `config('payment.paytr.notification_url')` puts the correct value under review in the repository, `php artisan payment:diagnose` prints it beside the credential check and the count of payments stuck pending, and the deploy run-book names the panel path and the grep that confirms delivery |

| 11 | Two finance screens lazy-loaded `currency` in a table column; a one-row test could never have caught it | **Fixed (2026-08-05).** `PaymentAdminResource` and `PayoutResource` both render `$record->currency->code` on a query with no eager load — under `shouldBeStrict()` that is a `LazyLoadingViolationException` and a page an admin cannot open, and in production a silent N+1. Both now override `getEloquentQuery()` with `->with('currency')`, the same shape `OrderResource` already used. A sweep of every Filament resource found no other case: the other candidates (`status`, `type`, `role`) are cast ENUMS, not relations. **The testing lesson is the durable part.** Every earlier Payment test drove an action, an endpoint or a policy — none ever RENDERED a table, so a green suite shipped a blank page twice. The new panel test asserts COLUMN STATE rather than a 200, because a page whose chrome renders while every row throws still passes a status-code smoke test; and it seeds **at least two rows per table**, because `Builder::hydrate()` only sets `preventsLazyLoading` when `count($items) > 1` — the first version of the test used one record, passed with the bug still in place, and proved nothing. Both fixes are pinned by removing them and watching the tests fail |

---

END OF FILE
