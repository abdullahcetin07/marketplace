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
| 6 | `StoreQueryContract::liveStoresForOrganization()` on frozen Store | **Ruled (Offer-required change).** Every existing method on that contract walks store → org, which is all an isolation check needs. Offer asks from the other end — "may this company sell at all, and under which storefront?" (Offer.md §3.4) — with no store uuid yet to ask about, so the precondition was unanswerable and the seller's offer form had nothing to attribute a listing to. One additive read method returning `uuid => display name`: the name is carried because a picker built from uuids alone would ask a seller to choose between two identifiers, and one method answering both the precondition (`=== []`) and the picker beats two that overlap. No models and no internal store ids, so the boundary is unchanged and Store gains no dependency. Same shape and justification as the `StoreManage` capability frozen Organization gained for Store |
| 6 | `CatalogBrowseContract` reads the database, not the search index | **Applied (deviation from Offer.md §8.2, reported).** The spec describes the seller's "select a product to sell" browse as reading Catalog's OpenSearch index. It reads Postgres instead: that index is tuned for BUYER relevance (Turkish analysis, boosts, facets — `docs/search.md`), whereas this is a seller filtering by category and brand for a product they already hold; and index-backed would put a cluster on an internal panel's critical path — a seller blocked from listing because OpenSearch is down, and a flow the suite (`SCOUT_DRIVER=null`) could never exercise. Cost accepted: `LIKE` matching, not relevance-ranked, and it will not scale to millions of rows. It sits behind the contract precisely so swapping to an index-backed implementation is one container binding. Buyer-facing search still goes to the index (Offer.md §10) |

---

END OF FILE
