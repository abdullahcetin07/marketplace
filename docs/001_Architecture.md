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

| 12 | ADR-063 Shipping fulfilment model | **Ruled.** Shipping is seller-fulfilled with **manual tracking** (cargo company from a `cargo_companies` lookup table + tracking number); no cargo-carrier API in v1 (a provider-agnostic `ShipmentTrackingContract` with no implementation yet). **One shipment per order** (multi/partial shipment absent). **v1 charges no shipping fee** — Shipping writes no price/KDV/commission; a priced flow is a future ADR. Imports no module |

| 12 | ADR-064 Delivery inference & downstream | **Ruled.** The **seller cannot mark delivered** (payout waits on it); delivery is inferred — buyer confirms ("Teslim aldım") OR `shipped_at + transit_days` sweep — setting `delivered_at` and emitting `ShipmentDelivered`. Payment subscribes by class-string: **auto-payout** at `delivered_at + payout_hold_days` (the automatic payout ADR-060 deferred; manual stays) and the **return/refund window** at `delivered_at + return_days`, which opens Payment's customer refund (P5's admin-only follow-up) and **line-level partial refund** (proportional commission/KDV reversal + PayTR partial refund + Inventory restock). Windows are `settings()`. A future carrier feed replaces the heuristic without changing downstream |

| 12 | Shipping S1 — the shipment aggregate, the carrier table and "kargoya ver" | **Applied (ADR-063, Shipping.md §2/§5/§6).** One shipment per paid order, created by a class-string subscription to `PaymentSucceeded` — Shipping is the **sixth module to import nothing**. **The UNIQUE index on `order_uuid` is the idempotency**, not the check-then-insert in front of it: PayTR retries until it hears "OK", so the listener runs many times for one payment and two arrivals can both see no shipment. `cargo_companies` is a lookup TABLE by the platform's own test of who owns the value — a carrier appears when the business signs a contract — and its **tracking-URL template is why the table earns its keep**: without it every surface showing a tracking number needs a per-carrier `match`, which means shipping frontend code each time operations adds one. **"Kargoya ver" is a one-way door:** a second call is a REFUSAL, not the no-op this codebase gives every other retry, because silently accepting would either discard a corrected tracking number or silently keep the old one — both leave the buyer with a link to somebody else's parcel. `shipped_at` is `now()` and never the caller's: a seller who could backdate a handover could shorten the transit window that infers delivery, and infer themselves an earlier payday |
| 12 | "The seller cannot deliver" is STRUCTURAL, not a policy carve-out — a corrected first attempt | **Applied (ADR-064), after getting it wrong once.** The first version had `ShipmentPolicy::before()` withhold the Super Admin bypass for `deliver`, and it could never work: `Gate::before()` in `AuthServiceProvider` grants a Super Admin every ability BEFORE any policy runs. A module carving itself out of that would also have made this the single place where "Super Admin bypasses every policy" (CLAUDE.md) is not what it says — and Shipping.md §6 explicitly anticipates an admin's *corrective* mark-delivered for a mis-swept auto-delivery, so "nobody, ever" was wrong about the spec too. **What actually holds is the absence of the operation:** S1 contains no action, no route, no form and no permission that writes `delivered_at`, and there is deliberately no `shipment.deliver` ability for anybody. The same reasoning `InventoryPolicy::update()` states for stock — an operation that does not exist is stronger than a permission nobody can spend. The policy still denies sellers explicitly, so a future "teslim edildi" button meets a documented refusal |
| 12 | `OrderQueryContract` gains two fulfilment reads | **Applied (Shipping.md §10, Order not frozen).** `orderFulfilment()` (seller + order number + status) and `paidOrders()`. The first is deliberately NOT `orderSettlement()`, which already returns the seller: that method's other half is a commission, and a fulfilment caller reading a money field to find out who packs a box is the reuse that makes a later change to either one break the other. `paidOrders()` is CAPPED BY THE CONTRACT rather than by the caller's discipline — "every paid order" grows without bound, and a port method that can return a million rows is one somebody eventually calls in a request. It feeds `shipping:backfill`, which gives orders paid before this module existed the parcel they never got; without it their sellers could never be paid out, because payout waits on delivery |

| 12 | Shipping S2 — delivery is inferred from two honest sources, and there is no third | **Applied (ADR-064, Shipping.md §3–§4).** The buyer's "Teslim aldım" or the transit sweep; both go through ONE shared concern (`RecordsDelivery`) because delivery writes three columns and produces the event two other modules key their clocks off — two copies would eventually differ by one field, and the field that went missing would be `delivered_via`, which is exactly the one a dispute turns on. **The guard lives in that concern, not in its callers:** only a shipment in transit may be delivered, so the sweep is idempotent and a buyer's second tap is harmless. Returning null rather than throwing is what makes both true at once. **`delivered_at` is never re-stamped** — it is a payout schedule and a return deadline, and moving it silently extends both; the sweep would also overwrite a buyer-confirmed delivery with a guess, which is the more expensive half. The event carries `deliveredAt` FROM THE ROW rather than letting a consumer read `now()`, because a payout date that depends on queue latency is one nobody can reconcile |
| 12 | The confirm endpoint is keyed on the ORDER, and a seller gets 404 rather than 403 | **Applied.** The customer already holds their order uuid; making them fetch a shipment uuid first would put a second identifier on a public surface for no benefit — one more thing to scope and probe. Ownership is asked of Order through the Core port, because a shipment carries who has to SEND the parcel, not who receives it — so `orderFulfilment()` gained `customer_id` (the internal id, compared against the authenticated actor, never leaving the application). A seller hitting the buyer's endpoint is authenticated but not the owner, so it answers 404 exactly as "no such order" does: nobody discovers which order uuids are real by watching a status code change. It is also the fourth closed door on a seller reaching delivery (ADR-064) |
| 12 | `OrderStatus` gains `Delivered`; the fulfilment states this enum kept refusing to invent | **Applied.** `Preparing`/`Shipped` are STILL absent and still belong to Shipping — where a parcel is, is a shipment's business, and duplicating it here would be a second source of truth for one fact. `Delivered` is different: it is when the order is complete from the customer's side, and it is what the return window and the payout clock are measured from. Order does not decide it — Shipping announces `ShipmentDelivered` and Order's own class-string listener moves the state, the same division as `PaymentSucceeded`. `Paid → Delivered → Refunded`: a delivered order stays refundable, which is the whole point of the return window |
| 12 | The fulfilment windows became `settings()`, and Settings gained a group | **Applied (ADR-064, Shipping.md §7).** `shipping.transit_days`, `payout_hold_days` and `return_days` are operator-tunable — the right answer comes from what carriers actually do and what support tickets say, not from a release — with `config('shipping.windows.*')` as the fallback, because Settings never breaks boot by design and a sweep that stopped running over a missing row would stop paying sellers. `SettingGroup::Shipping` is the first group whose values change what the platform DOES rather than how it looks, and deliberately not restricted: these are operations' numbers, not a super-admin lever. `return_days = 14` is the Turkish right of withdrawal (cayma hakkı) — shortening it is not a configuration choice the law allows, which the seeder says out loud |

| 12 | Shipping S3 — payout waits on DELIVERY, and a balance stopped being one number | **Applied (ADR-064, Payment.md §8), and it is a behavioural change to P4's ceiling.** Payment consumes `ShipmentDelivered` by class-string and freezes two dates per order in `settlement_windows`. `CreatePayoutAction` no longer draws on the whole balance: `SellerBalance` splits it into BALANCE (Σ of the ledger, unchanged), ON HOLD (the net of orders not delivered long enough) and PAYABLE, and the ceiling is the last of those. A seller must not be paid for goods the buyer can still send back — which is the entire reason payout waits on delivery rather than on payment. **Two rules make the arithmetic honest in both directions:** an order with NO window is held (reading it as payable would pay the seller the moment the card cleared, exactly what ADR-064 prevents), and a ledger entry with no `order_uuid` is NEVER held (an adjustment cannot be tied to a parcel, and holding it would freeze money with nothing that could release it). A refunded order nets negative and is not "held" either — withholding a debt would ADD to what the seller may draw. **P5's refund-after-payout test now has to deliver first**, which is the change working rather than a nuisance of it |
| 12 | The windows are frozen columns, not a computation over `settings()` | **Applied.** Deriving `payout_eligible_at` on read would let an operator shortening the hold make last month's deliveries retroactively payable, and one lengthening it withdraw a payout a seller had already been promised. Freezing them is the ADR-053 discipline applied to a date instead of a price: the rule that applied when the event happened governs it. `delivered_at` likewise comes off the EVENT rather than the consuming clock — a queued listener running an hour late must not push a seller's payday an hour out, and a payout date that depends on queue latency is one nobody can reconcile. A malformed date is a REFUSAL, not a fallback to `now()` |
| 12 | Automation gives eligibility, not payouts | **Applied, and it is the reading of "auto-payout" the work order allowed.** Nothing auto-creates a `Payout` row: a payout is a bank transfer a human actually makes (ADR-062 — the software moves no money), and one per delivered order would fragment a seller's money into dozens of tiny transfers somebody has to execute by hand. What the delivery automates is the DECISION SUPPORT — `payable_minor` and `on_hold_minor` on the balance endpoint and beside the amount field on the payout form, so an admin sees what they may send and how much more is coming instead of typing a number that is refused |

| 12 | Payouts are created AUTOMATICALLY — the owner reversed S3's "decision support only" | **Applied (owner decision, 2026-08-06).** `CreateDuePayoutsJob`, daily, proposes ONE pending payout per seller for their whole payable balance. S3 had argued for eligibility-only on the grounds that per-order payouts would fragment a seller's month into dozens of hand-executed transfers; the owner's change keeps that objection answered — the job batches PER SELLER, not per order — while automating the decision itself. **The bank is still not automated:** a human makes the transfer and marks it paid, so ADR-062's "the software moves no money" is untouched. **It writes no rows of its own**, calling `CreatePayoutAction` so the row lock, the payable ceiling and the `payout_debit` are the same code a manual payout uses — a job inserting `payouts` directly would be a second, quieter path to moving money. Three guards stop a double payout and only the first is the job's: a seller with a pending payout is skipped, creating one debits the balance to zero, and the action's lock serialises overlapping runs |
| 12 | `payouts.created_by` becomes nullable: NULL means the schedule decided | **Applied.** The alternative was a synthetic "system" user row, and it is the worse one — a real row in `users` that can be authenticated against, granted roles and impersonated, an account nobody owns holding the authority to move money. An absent actor cannot be logged into. It is also the column that tells the two kinds of payout apart, which an operator reconciling a morning batch actually needs, and it answers that question once rather than via a second boolean that could disagree with it. The migration's `down()` is deliberately a no-op with the reason stated: by the time anyone rolls back there are automatic payouts with no actor, and re-imposing NOT NULL would have to invent an admin who decided them |


| 12 | Shipping S4 — a refund names LINES, and the buyer gets a door of their own | **Applied (ADR-064, Payment.md §8).** P5 refunded whole orders and said what it was waiting for: a fulfilment state to judge a customer-initiated refund by. S3's delivery date and return window supplied it. A refund still names **no amount** — it names lines and quantities, and the platform prices them from the frozen snapshot. **The KDV needs no separate term**, which is the part that surprises: Turkish retail prices are KDV-inclusive (ADR-055), so refunding `unit_price × qty` refunds the tax with it in exactly the proportion it was charged, and a "proportional KDV" line on top would give the buyer the tax twice. The commission is the **frozen figure scaled** (ADR-061), half-up, in integer arithmetic with no float constructed anywhere — re-resolving the rules would apply today's rates to last month's sale. **The last unit of a line takes the remainder**, billed as "everything not yet refunded" rather than as a multiplication, because `line_total` is not always `unit_price × quantity` to the kuruş and otherwise a fully returned line would strand a kuruş forever |
| 12 | Two unique indexes had to go, and the guarantee that replaced them is weaker | **Applied, and stated rather than glossed.** `payment_refunds` was UNIQUE on `(payment_id, order_uuid)` and `seller_ledger_entries` on `(payment_uuid, order_uuid, type)`. Both existed for good reasons — a human clicks refund twice, and PayTR retries a callback until it hears "OK" — and both forbid something S4 makes legitimate: a **second refund of one order**, one shoe today and the other next week. What replaces them is arithmetic: a line may go back up to its REMAINING quantity, summed from `payment_refund_lines`. **A constraint cannot be forgotten and a sum can.** So the check lives in exactly one place (`RefundableLines`), asking for three of two is a REFUSAL rather than a clamp, and the compensating cover is the pre-existing application check in `CreditSellerLedger` plus the idempotent double-callback test, which still asserts one credit after two callbacks. A `(payment_refund_id, order_line_uuid)` unique index would win some of it back and is recorded as a follow-up |
| 12 | The buyer's return did NOT attach where P5 predicted | **Applied.** P5 left the seam at `PaymentPolicy::refund()` — "when Shipping ships, only this method changes". It was the wrong seam, and the reason is worth keeping: ownership, delivery and a clock are questions about an ORDER and its parcel, not about a payment. So they live in `RequestReturnAction`, which reads them through the Core Order port, and the ability keeps meaning exactly what it meant — who may reverse a charge **without** those conditions holding. Two doors, one machine: the buyer's action calls the same `RefundLinesAction` an admin does, because two implementations of the same money is how they stop agreeing. Every refusal answers alike — "not yours", "never delivered", "too late", "no such order" — or the error tells a prober which one it was. **The admin needed the line-level door too**, which is S4 cleaning up after itself: the whole-order path skips an order that already has a refund row (correctly, or it would refund the returned unit twice), so a partly returned order would otherwise have been stuck that way forever |
| 12 | `checkoutGroupFor()` — a derivation that worked, deleted on cost | **Applied (Order not frozen).** Payment can find an order's checkout group by walking `ordersForCheckoutGroup()` over its own settled payments, and the first draft did, on the argument that a port should not answer what a caller can already derive. That argument survives contact with a scan of every settled payment on the platform plus a query per payment only until the endpoint is one a customer taps. **No new fact crosses the boundary** — the group uuid is already on the order and already leaves through the same port in the other direction. The general rule: "derivable" is about correctness, not about whether the derivation is affordable |

| 13 | ADR-065 Pre-shipment cancellation | **Ruled.** While a shipment is `pending`, a paid order cancels two ways, both reusing S4's line-level refund (proportional commission/KDV reversal + PayTR partial refund + Inventory restock): **seller line-level cancel** (immediate, a quantity of a line the seller can't fulfil) and **buyer cancel-request → seller approve/reject** (full order; the buyer can't cancel a paid order unilaterally). The gate is the shipment state, not the return window — once `shipped`, ADR-064's return takes over. Only the triggers + the shipment-pending gate are new; the refund is `RefundLinesAction` unchanged. No seller penalty in v1 (future ADR) |


| 12 | Cancellation C1 — the seller sheds a line, and the refund is S4's, unchanged | **Applied (ADR-065).** A paid order whose parcel has not left can be cancelled per line and per quantity, and the money is `RefundLinesAction` with one field different: same kuruş, same proportional commission, same restock, same two ledger entries. **The gate is a shipment STATE, not a clock** — once the box is with a carrier the seller has spent the effort and the buyer's route is the return (ADR-064) — and it is read through a new `ShipmentQueryContract`, Shipping's first downstream port. **A missing shipment REFUSES rather than assuming "not shipped yet"**: that assumption refunds a parcel that may already be moving, which is the one mistake here nothing later can undo; `shipping:backfill` is the smaller problem. Ownership is re-checked behind the port even though the panel already scoped its query, because a panel's tenancy is a query somebody can get wrong |
| 12 | The platform's SECOND Core command port, and why an event could not do it | **Applied.** The seller cancels from the screen where they see their orders — Order's — and the refund is Payment's, and neither module may import the other. `OrderCancellationContract` sits between them. It is a COMMAND port rather than an event because the seller must be told **in the same request** that they asked for three of two or that the parcel already shipped; an event announces a fact, and a panel button needs an answer. It carries a read as well (`cancellableQuantities`): the form's per-line caps are a subtraction only Payment can make — the order knows what was bought, Payment knows what has already gone back — and asking two ports for halves of one number is two round trips. The caps are a HINT; `RefundableLines` re-checks every quantity behind the port, because two people can hold one screen open |
| 12 | `PaymentRefunded` carries WHY, and that is a field rather than a second event | **Applied.** The ledger cannot tell a pre-shipment cancellation from a post-delivery return — the amounts are identical — but everything downstream must: a cancelled order becomes `cancelled` with a `cancelled` parcel, a returned one `refunded` with a `returned` parcel, and a buyer told their parcel was "iade edildi" when nobody ever packed it is a support ticket. **Two events were the obvious alternative and are the wrong one**: they would put two listeners in `SettleOrdersOnPayment` racing to set different terminal states on one order, decided by listener registration order. One event, one cause, one answer. It is a STRING on the payload, not the `RefundCause` enum, because the consumers subscribe by class-string precisely so they import nothing from Payment — a typed payload would undo that in one hint |
| 12 | Opening `Paid → Cancelled` armed a lever that had been safe by accident | **Applied, and it is the cost of the edge.** `CancelOrderAction` — the plain lever that releases a hold and, for a seller, ZEROES their declared stock (ADR-057) — guarded on `canTransitionTo(Cancelled)` alone. That was sufficient only while the transition did not exist: the moment ADR-065 added it so a cancellation could name its outcome honestly, the old lever could cancel a PAID order, releasing an already-committed hold and leaving the buyer's money exactly where it was. **A transition table is the wrong place to keep a rule once two legitimate paths reach one state.** The action and `OrderPolicy::cancel()` now ask `OrderStatus::isCancellableWithoutRefund()`, and a test pins that the plain lever throws on a paid order while the transition itself stays legal |


| 12 | Cancellation C2 — the buyer asks, and the aggregate that records it holds no money | **Applied (ADR-065).** A paid order may already be picked and packed, so the buyer's button writes a `pending` **request** and nothing else moves — no money, no stock, no order status. `CancellationRequest` went to **Order**, which is where the ADR's own wording pointed ("with the order lifecycle") and where it belongs by content: it has no amount, no quantity and no line, because the refund, the commission reversal and the restock all happen behind C1's Core port, in Payment. **An approved request is not where the cancellation lives** — the ORDER is cancelled, by `PaymentRefunded`'s cause like every other cancellation here, and this row only records that the seller said yes. Two rows both claiming to hold one fact is how they start disagreeing |
| 12 | "One open request per order" counts only `pending`, and is stated twice | **Applied.** An unconditional unique on `order_uuid` would make a single refusal permanent, and that is wrong: a seller who said no on Monday may say yes on Thursday while the item still has not shipped. So the partial index keys on `status = 'pending'` — **PostgreSQL only**, exactly as `customer_addresses`' default-address indexes are (2026-10-01), because the suite runs on SQLite. The guarantee is therefore stated twice: the action checks inside its transaction (tested everywhere) and production keeps the database backstop for the double-click the check cannot see (tested on the real engine). A second ask while one waits is a **refusal**, not a silent no-op returning the open row — the two are indistinguishable to the buyer and only one is true |
| 12 | The approval re-asks the gate, and refunds before it stamps | **Applied, and both are orderings rather than features.** A request can sit for days; the parcel may leave meanwhile. So `ApproveCancellationAction` asks the port for what is still cancellable rather than trusting the request's age, and refuses out of the same method the buyer's own attempt would have hit. And the refund runs BEFORE the row is stamped `approved`: the reverse leaves an approved request beside money that never moved if the PSP refuses — every surface claiming a cancellation that did not happen — while this way a failure leaves a `pending` request beside a cancelled order, visibly odd and already right about the money. `ApproveCancellationAction` is deliberately **not** a `BaseAction`: an outer transaction would turn the refund's commit into a savepoint release, and Order and Shipping would move an order and a parcel on a transaction still able to roll back |


| 12 | `StoreQueryContract::publicProfilesFor()` gained `slug` | **Applied (2026-08-06), a frozen-Store change a later surface requires** — the same footing as the two methods Offer added, and the third the freeze has granted. The storefront's store page is path-addressed at `/magaza/{slug}` (ADR-035; custom domains cut from v1), while both surfaces that hold a store uuid and render its name — the buy box and the customer's own order list — could only ever SAY where something was sold. A name identifies a shop; a slug reaches it. **It rides the same live-only query as `name`, and that is the safety property rather than an implementation detail**: a suspended shop that must not be NAMED in a public payload certainly must not be LINKED from one, and the filter being the query's means a caller that forgets cannot leak either field. Order picks it up **batched** through the Core contract and stamps a transient attribute — no column, no cast, no N+1 on a list a customer opens constantly — and imports no module doing it. Cost: one more field on a read that already existed, and the shape of a frozen module's contract moved for a third time |


| 13 | `OrderQueryContract` gains `deliveredPurchaseLines()` — the review gate | **Applied (ADR-067, Reviews R3).** Reviews may not read `orders`, so this method is the only thing between "a buyer says they bought it" and the platform knowing they did. **It returns LINES, not a boolean**, because the aggregate binds to one delivered order line: the "Değerlendir" screen must show WHICH purchase it is offering to review, and the seller tag the review is stamped with comes from here — which is why `SubmitReviewDTO` has no field that could carry one. It is keyed on **(customer, product)**, a question no existing method answered: every other one is order-uuid-centric because every earlier consumer already knew which order it meant, and Reviews starts from a product page and a session. **Delivered only** — the gate is delivery, not payment; a paid-but-unshipped order has no experience to report |
| 13 | Two deviations from `Reviews.md` §5's sketch, recorded rather than taken silently (ADR-018) | **Reported.** The spec sketched `deliveredPurchaseLines(int $customerId, …)` returning a `delivered_at`. Neither survived contact with the schema. **The customer crosses as a UUID**: `orders` carries and indexes `customer_uuid`, Reviews stores both halves of the ADR-040 pair, and a port that can be satisfied without an internal id should be — though the contract is NOT uuid-only in general (`orderFulfilment()` returns a `customer_id` on purpose, to be compared against the authenticated actor). **And there is no `delivered_at` to return**: delivery on an order is the STATUS alone, and the timestamp lives on Shipping's `shipments` — a table Order must not read and Reviews may not either. The field is `purchased_at`, from the order's `placed_at`, **named after what it is**: a caller told "delivered_at" would build a review-window on a date that is really a purchase date. v1 has no window, so nothing depends on the difference yet — which is exactly when to get the name right |

| 13 | Order gains `Expired`, and a sweep that pays back what ADR-057 borrowed | **Applied (ADR-072).** ADR-057 made placement HOLD a reservation and Payment commit it, and nothing released the hold when a shopper closed the tab at the card form. The hold sat **forever**: a seller's `available = on_hand − reserved` fell toward zero and their offer dropped off the buy box **while still declaring stock**, so every abandoned checkout permanently cost that seller inventory. A minute-by-minute sweep now expires `AwaitingPayment` past `settings('order.payment_window_minutes')` (default 5, floored at 1 in code) and gives the holds back. **`Expired` is not `Cancelled`** — a cancellation is somebody's decision, an expiry is the clock — which is why it is a separate action rather than a `CancelOrderAction` variant: the seller-cancel lever ZEROES a seller's declared stock (ADR-057), and doing that because a stranger abandoned a basket would be the opposite of the fix. It stamps **no `cancelled_at` and adds no column**; `placed_at` plus the status already say when the window started and how it ended. **The first scheduled run self-heals the backlog** — everything stuck since before this shipped is past the window by definition |
| 13 | The sweep that had never run at all, and the one nobody had scheduled | **Applied, and it is the larger half of the bug.** `ExpireReservationsJob` (the pre-placement `Pending` window, 30 min → `Cancelled`) had existed since Order shipped and was never scheduled; neither was anything else, because **the scheduler had never run on this server**. Eleven tasks were silently due and skipped — no delivery was ever inferred and no seller was ever paid. **A scheduler that is absent looks exactly like one with nothing due**, and `schedule:list` shows what *would* run, not that anything does. Both sweeps are now `->everyMinute()->onOneServer()`, and a systemd unit runs `schedule:work` as `www-data` beside Horizon and the storefront |
| 13 | A payment that succeeds after expiry: re-reserve or refund, never oversell | **Applied (ADR-072), and it is the intricate part.** A five-minute window is shorter than a slow 3-D Secure, so a verified success lands for orders whose holds are already back on the shelf. `SettlePaymentCallbackAction` takes them back **first**, **all-or-nothing across the group**: every line re-reserves → settle normally and the orders recover `Expired → Paid` (the one transition out of `Expired`); any line cannot → release what this attempt took, refund the charge **in full**, go terminal `Refunded`, dispatch `PaymentFailed` rather than `PaymentSucceeded`, and leave the orders expired. **Half a basket is not an outcome** — a basket is one charge (ADR-052/060), and there is no partial refund of a payment that was never split. **A refund the PSP refuses is logged and still terminal**: leaving the payment settleable would let the next retry commit stock nobody has. It lives in Payment because Order holds no Inventory port on that path; Order's whole contribution is that the transition is legal |
| 13 | The re-reserve found a silent oversell in Inventory that predates it | **Applied — a change Payment required of a module that is not frozen.** `ReserveStockAction` treated ANY existing reservation for a reference as "already held" and returned success **without locking the pool or checking availability**. So re-reserving a *released* hold was a no-op, and the commit that followed found a non-active reservation and moved nothing: money taken, `on_hand` untouched, and no error anywhere. The idempotency check now asks `ReservationStatus::isReclaimable()`. `Active` is still idempotent — a retrying caller must not take a second hold, which is the promise that shape was written for — and `Committed`/`Restocked` still refuse, because those units have left. Only `Released` is reclaimable, and it goes through the **full** path: lock, availability check, its own ledger entry, and the ability to **fail**. **A re-hold is a new claim on stock, not a repeat of an old one**, and the distinction was invisible while nothing ever re-held a released reference |

| 13 | A return stops being an instant refund, and the money moves to the END of it | **Applied (ADR-073, amends ADR-064).** S4 refunded the moment the buyer asked — "the window IS the approval" — which for physical goods pays out before the seller has anything back. A return is now REQUESTED, **approved with an iade kodu**, and **completed** when the parcel is on the shelf; only that third step calls `RefundLinesAction`. **The money machine did not change, only its trigger**: same lines, same proportional commission, same restock, same `cause: return`. The one structural difference from ADR-065's cancellation request is that **`Approved` is still OPEN** — the buyer is walking to the cargo desk — so the partial unique index counts two states where the cancellation's counts one. **Refund first, stamp second**, so a PSP refusal leaves the request `Approved` rather than `Completed` beside money that never came back. Cost, stated: the buyer waits for the seller twice, which is the correct trade for not refunding on trust |
| 13 | The platform's THIRD command port, and why C1's could not be reused | **Applied.** `OrderReturnContract` is `OrderCancellationContract`'s exact shape — the seller acts from a screen Order owns, the refund belongs to Payment, neither imports the other, and an event will not do because a seller pressing "İadeyi tamamla" must be answered in that request. It is a separate port for two reasons that are the two halves of one lifecycle: C1 **refuses a shipped parcel**, which is precisely the state a return begins in, and it **hard-codes `cause: cancellation`**, which decides whether an order ends `cancelled` or `refunded` and its parcel `cancelled` or `returned`. Borrowing it would have told every buyer their delivered parcel was cancelled. Same machine, opposite gate, opposite meaning — two ports and one action, not one port with a flag. `ShipmentQueryContract` gained `activeCargoCompanies()` alongside, so the approval form can offer Shipping's carrier list without Order importing `CargoCompany` |
| 13 | The POST that refunded is DELETED, and a test guards its absence | **Applied.** `POST /orders/{order}/return` and `RequestReturnAction` are gone; the buyer's write is `POST /orders/{order}/return-request`, whose **201 means "talep alındı" and not "iade edildi"** — the route it replaced returned the same code and meant the opposite. A test asserts the old path 405s, because if it returns through a merge or a helpful restore, buyers get their money before sellers get their goods and nothing else in the suite would notice. **The GET stayed in Payment**: every number in it — what has already gone back, what the platform will pay for the rest, the last unit's remainder — is Payment's arithmetic, and moving it would have meant Order asking for all of it through a port and re-rendering a quote nobody needs |
| 13 | The admin's plain-cancel button was offering an operation the domain forbids | **Applied (ADR-065/073, bundled).** `->visible()` asked only `can('cancel')`, and **Super Admin bypasses `OrderPolicy::before()`** — so the button appeared on paid and delivered orders for exactly the actor most likely to press it, and `CancelOrderAction` then threw, because it refuses on `isCancellableWithoutRefund()`. The status guard is now on the visibility of both the admin and the seller resource. **A paid order is undone by a refund, or not at all**: the return, the cancellation request, or the admin refund surface. A button whose only outcome is an exception is worse than a missing one — it tells an operator the operation exists |

| 13 | The refund had never worked, and the recorded diagnosis was wrong | **Applied (2026-08-09).** Every PayTR refund the platform has ever attempted was refused, and it was written down — in the work order and in this repo — as "confirm refund capability in the merchant panel". The `errors` log had been saying otherwise since 2026-08-07: **`err_no: 004`, "paytr_token gonderilmedi veya gecersiz"**. `PayTrGateway::refund()` hashed `merchant_oid + return_amount + salt` and omitted the **`merchant_id` PayTR's iade API puts first** — the same leading field `tokenHash()` has always had on the way in. **A wrong hash and a disabled capability are indistinguishable from outside**, which is precisely the failure `tokenHash()`'s docblock predicted — "refused, forever, with no clue as to why" — and the thing that made it findable at all was S4's `logRejection()` writing PayTR's verbatim `err_no` down. The lesson worth keeping: a rejection recorded as a *guess* about its cause outranked a log line stating its cause, for two days. **Still unverified against live PayTR** — proving it takes one real refund on a real charge |
| 13 | An integration test was writing to the live database and never cleaning up | **Applied.** `CheckoutOnPostgresTest` reaches the real `pgsql` connection deliberately — that is the file's whole purpose, since `RefreshDatabase` covers only the default connection and the uuid-column traps it guards exist only on PostgreSQL. What nobody noticed is that nothing rolls those inserts back: **101 orphaned `cancellation_requests` had accumulated**, three per suite run since C2, and ADR-073's new index test was adding three more. Harmless where they sat — every surface reaches these rows through their order, so an orphan is invisible — but a table growing by six a run is one somebody eventually has to explain. Both tests now delete what they wrote. The accumulated rows are left in place pending a decision: they are provably orphaned, but they are live data |

| 14 | ADR-074 bulk catalogue import — it DRIVES the authoring actions rather than writing models | **Applied.** An admin uploads an Excel/CSV and each row becomes a category path + brand + product + one default variant + KDV bracket + images, published. The instruction that shapes everything: `CatalogRowImporter` calls the same actions a seller's "ürün aç" does. Writing models directly would have been shorter and would have skipped the moderation lifecycle, the slug registry, the GTIN guard, `combination_key` and every event other modules consume — producing rows that look right in the admin table and are invisible to search, the storefront and Offer. It also means the import can do nothing a human could not: the submit gate still demands a variant and a tax bracket, and publish still checks the category's required attributes. **Idempotent on GTIN**, which is what makes a correction pass possible at all: fix three cells, re-upload the sheet |
| 14 | The work order's category walk would have corrupted the tree, silently | **Reported and NOT taken (ADR-018).** It specified `findBySlug(Str::slug($segment))` per path segment. `categories.slug` is UNIQUE across the whole table, so once "Erkek > Ayakkabı" exists, a row for "Kadın > Ayakkabı" matches the MEN'S category and files every women's shoe under it — **every row succeeds, the failure report is empty, and the catalogue is wrong until somebody browses it months later.** A path segment is only meaningful relative to its parent, so segments are matched by NAME WITHIN A PARENT. A test pins the two-paths-one-word case, which is red under the specified algorithm. Two smaller departures beside it: an existing `accepts_products = false` category fails the row instead of being flipped (that flag is ADR-047's moderated decision, not a spreadsheet's), and the three lookups moved into `CatalogTaxonomyResolver` because the importer had reached nine constructor dependencies against a ceiling of seven |
| 14 | Two APIs the work order named do not exist in this Filament version | **Reported.** `ImportAction::downloadableExampleFileContent()` is not there — the example content belongs on `ImportColumn::example()`, and the modal's own download button is generated from those. And `can('moderate', Product::class)` would have thrown: `ProductPolicy::moderate()` is typed `(User, Product)`, so a class-string check hands a string where a model is declared, on a page that would then never open. Gated on the permission directly. **Filament's one-row-one-model shape needed three overrides**, which are the real integration: `resolveRecord()` does the work and returns the finished Product, while `fillRecord()` and `saveRecord()` do NOTHING — `baslik` is not a column on `products`, and the actions have already committed |
| 17 | ADR-078 — "Çok Satanlar" + "En Çok Değerlendirilenler" ranking read endpoints | **Accepted (extends ADR-077).** Two always-on homepage strips the site had deferred, same compute-on-read stance as also-bought. `GET /products/best-sellers` (ranked by units sold across **paid** orders, via a new Core `OrderQueryContract` method) and `GET /products/most-reviewed` (ranked by published review count, via a new Core Reviews query method). Both return published+sellable `ProductCard[]` (≤12), no stored ranking table — the truth is the order lines (ADR-053) and reviews (ADR-069). Catalog hydrates the ranked uuids preserving order; no module import. `[]` until data exists, so the storefront (already wired, degrade-to-empty) hides each strip until sales/reviews accumulate. "En yüksek puanlı" left out (noisy at low counts). Cache now; precompute if it bites. Work order: `BUILD_RANKING_STRIPS.md`. |
| 20 | ADR-086 — the Google Merchant feed is a nightly FILE built from Core contracts, and refuses to publish itself empty | **Accepted (2026-08-22).** Google Shopping needs a product feed; this builds one nightly (`feed:build-google-merchant`, 04:15) to `storage/app/public/feeds/google-merchant.xml`, served by `GET /feed/google-merchant.xml`. **A file, not a rendered response** — 20k items inside a request times out *against Google*, which records it against the Merchant Center account. **Single merchant** (ADR-060): the buy box winner's price, **KDV-inclusive** and therefore with no `tax` node. **Lives in Catalog and imports no module** — catalogue fields off its own models, price via `OfferQueryContract` and availability via `InventoryQueryContract`, batched per chunk (the ADR-079 lesson); no price/stock column enters Catalog. **It drops what Google would reject and counts it** (no description/image/offer, excluded category branch) because a rejected item is worse than an absent one, and the "no description" count is the platform's only measure of its Turkish-copy backlog. **An empty feed never replaces a good one**: zero items is well-formed XML meaning *this merchant sells nothing*, so the build keeps the previous file and exits non-zero. Public id is the variant uuid; `link` is the flat slug (ADR-059) on the storefront host, not `app.url`. Cost: a day of price/stock staleness, `google_product_category` unmapped in v1, inert without the scheduler — and gated on copy, since all 7,025 sellable products currently have empty descriptions. Work order: `BUILD_GOOGLE_MERCHANT_FEED.md`. |
| 19 | ADR-085 — analytics is one GTM container, consent-gated by default, and the only licensed price parse | **Accepted (storefront-only, extends ADR-058).** The storefront loads a single Google Tag Manager container, **env-gated on `NEXT_PUBLIC_GTM_ID`** so staging is never measured. **Consent Mode v2 defaults to denied** before GTM loads (a `beforeInteractive` script), and the KVKK banner (`CookieConsent`) `update`s to granted only on the shopper's choice — measurement is opt-in, and a returning grant re-applies silently each load. GA4 ecommerce (`view_item`/`add_to_cart`/`begin_checkout`/`purchase`) crosses the **dataLayer**, forwarded by owner-wired GTM tags, so tagging changes are never a deploy; every push resets `ecommerce` first and no-ops without a dataLayer. **The one licensed `Number(price)` on the platform** lives in `analytics.ts` (`analyticsAmount()`) because GA4 requires numeric `value`/`price` where the API sends decimal strings (ADR-005) — quarantined to one grep-able file, degrades to `0` not `NaN`, feeds a report and never a displayed total. Cost: measurement is only as good as the hand-wired container, and declined/unanswered visitors are cookieless — a correct privacy trade and a named gap in the numbers. |
| 18 | ADR-080 — listing filters are faceted (price range + brand), computed on read in the browse meta | **Accepted (extends ADR-058).** Category/brand/search listings sorted but didn't filter. Browse gains `price_min`/`price_max` (decimal strings → minor units, applied against the Offer buy-box price) and a `meta.facets` block: `brands` (present in the current category/q, with counts) + `price` `{min,max}` bounds. Facets computed over the query minus the applied brand/price so options stay visible. `category+brand` already worked (verified live: 1,138 → 20); this adds the price filter + the facet data the UI needs. Price reads through the same Core `OfferQueryContract` the sellable-wall uses (no Catalog→Offer import); `is_sellable` (ADR-079) keeps it cheap. Filters live in the URL (shareable, `noindex,follow`). Rating/attribute facets deferred. Work order: `BUILD_LISTING_FILTERS.md`. |
| 16 | ADR-077 — "also bought" recommendations computed on read from paid-order co-occurrence | **Accepted.** The product page's "Bu Ürünü Alanlar Bunları da Aldı" strip needs a source; there is no behavioral data today. A public `GET /api/v1/products/{product}/also-bought` returns products co-occurring in the same **checkout group** (ADR-052) across **paid** orders, ranked by frequency, filtered to published+sellable, as `ProductCard[]`. **Computed on read, no stored recommendation table** — the truth is the immutable order lines (ADR-053), same stance as the buy box (ADR-045) and rating average (ADR-069). Reads Order via a **new Core `OrderQueryContract` method** (co-purchased uuids); Catalog hydrates the cards — no module import. Returns `[]` until sales exist, so the storefront (already wired, degrade-to-empty) hides it and it appears on its own once orders accumulate. Cache now; precompute into a rebuilt table if it grows. Same family as the deferred best-sellers/most-reviewed strips. Work order: `BUILD_ALSO_BOUGHT.md`. |
| 15 | ADR-076 — sellers feed price + stock through a token-authed offer sync API and a CSV mirror | **Design approved (ADR-076, extends ADR-042/043).** Offers were entered one form at a time; a real store is thousands of SKUs whose price/stock move daily, and the catalogue import (ADR-074) loads catalogue, not price/stock — so products sat with nobody selling them. The feed is **two doors over one brain**: a `SyncSellerOfferAction` (upsert by seller org + variant) driven by a **token-authed REST API** (the priority — Sanctum per-seller) and a **CSV import** in the seller panel. It feeds price+stock only and **never creates a product** — an unmatched/unpublished GTIN is a failed item; product creation stays the catalogue import's job. Required **one read-only method** on Core `CatalogQueryContract` (`publishedVariantUuidForGtin`); Catalog stays unaware of Offer. The load-bearing rule (from ADR-074): the feed **drives the existing offer actions**, it does not write the model, so `OfferCreated/OfferStockChanged` still reach Inventory's on-hand mirror (ADR-048) and the search index. Absolute stock, decimal-string price → minor units, idempotent. Spec: `docs/superpowers/specs/2026-08-11-seller-offer-feed-design.md`. Work order to follow. |
| 14 | ADR-075 — the import opens a category IT created; a human-closed one still refuses; and the retry storm is capped | **Applied (ADR-075, amends ADR-047).** The first real import failed 5 rows, all one cause: a real catalogue sells a product directly at a node that is **also a parent** (a product at *Cilt Temizleme Ürünleri* while that node has a *Cilt Temizleyiciler* child). The import had created that middle node **closed** on the way down — ADR-047's default — then refused the row against its own seconds-old flag. The fix distinguishes **who** closed the category: a node the import created carries no human decision, so a row terminating there **opens it**; a node a human left closed in the Category Manager is a real judgement and the row is still refused and reported. A boolean origin marker on `categories` (`created_by_import`) tells them apart and keeps re-runs idempotent. ADR-047's invariant is preserved verbatim — the import does not overrule the Category Manager, it only stops overruling **itself**. **Bundled hardening, a separate concern:** `ImportCsv` had no `$tries` ceiling and no `$backoff`, so those 5 rejected rows drove **29,074 attempts** and ~155,000 duplicate failure rows overnight before the 24h `retryUntil` closed. A rejected row now fails at the **row** level (recorded, chunk continues) and never throws out of the chunk job, and the job carries an explicit `$tries` + `$backoff` so no future defect can turn one bad row into tens of thousands of retries. Work order: `BUILD_CATALOG_IMPORT_FIX.md` |

| 15 | ADR-076 seller offer feed — two doors, one brain, and the same load-bearing rule as the catalogue import | **Applied (P1–P4).** Sellers created offers one form at a time; a real store is thousands of SKUs whose price and stock change daily. A token-authed REST API and a seller CSV import are thin adapters over `SyncSellerOfferAction`, which **drives `CreateOfferAction` / `UpdateOfferPriceAction` / `UpdateOfferStockAction` and writes no `Offer` model**. Those actions emit the events **Inventory mirrors on-hand from** (ADR-048) and search consumes, so a model write — shorter, obvious — would produce an offer that is correct in the table and invisible to availability and to search. The tests assert INVENTORY rather than the offers row for exactly that reason. **`Unchanged` is a first-class outcome**: a daily full-catalogue push mostly repeats itself, and skipping untouched fields means the loudest consumers are not woken four thousand times to be told nothing |
| 15 | ADR-076 — the feed needed its own Sanctum guard, and `present` speaks column names | **Two fixes the live smoke test found and the P2 suite had not.** (1) The platform's `sanctum` guard is bound to the **`customers`** provider, so a seller's bearer token authenticated against the token table and was then refused by `hasValidProvider()` — a `401`. Every test signed in through the named `seller` guard the way the panel does, which never exercises the token path at all. The feed is the first surface a seller reaches with a TOKEN, so it gets **`auth:sanctum_seller`** (provider `sellers`), placed as a SIBLING of the `auth:sanctum` group rather than inside it, because route middleware accumulates and the outer guard would run first and reject. `current_actor()` and `BaseRequest::actor()` learned the token guards too — a bearer token populates no named guard, so a correctly signed request otherwise authenticated and then read as nobody. Isolation is now the guard's rather than a policy's: an admin or customer token gets `401`, refused before anything reads what it asked for. (2) `UpdateOfferPriceDTO::has()` is asked **`'list_price_minor'`** — the column name — and the feed passed the DTO property name, so every struck-through price a seller sent was dropped; worse, `pricingChanged()` then saw a difference on every re-send, so an unchanged catalogue reported `Updated` forever and wrote an audit entry for an edit nobody made. A price sent alone is now also checked against the STORED list price, so that refusal is a machine reason per item rather than a `500` for the whole batch. |
| 15 | The merchant is never in the payload, which IS the authorization model | **Applied.** Neither the JSON nor the CSV has an organization field, so there is nowhere for a token or a spreadsheet to name somebody else's shop. `SellerFeedIdentity` resolves the acting merchant from the authenticated user through the seller panel's own chain — memberships the actor may MANAGE, then that org's live stores — so both doors can only ever offer the same set. A test attacks it from the other side: listing against a RIVAL's product is ordinary competition on a shared catalogue (ADR-037) and succeeds, while the offer lands on the token's own org. A seller with no live store is refused for the whole call rather than defaulted, because defaulting would create offers nobody can see. **No new permission**: a token authenticates you as yourself, and `auth:sanctum` consults all three named guards here — what stops an admin or customer token is the actor-type check, asserted because nothing else would catch its removal |
| 15 | A batch is a REPORT, and the ceiling is a refusal rather than a truncation | **Applied.** Every feed call answers `200` with a per-item result even when items failed: forty stale barcodes must not cost a seller the 3,960 that were fine, and their system branches on the machine reason (`product_not_in_catalog` means "ask us to add it", `offer_not_found` means "send a price first"). `422` is only for a malformed request or one over `offer.feed.max_batch` — **refused, never truncated**, since processing the first 500 of 4,000 would report success while three quarters of a catalogue went nowhere. Money crosses as a decimal STRING and becomes kuruş once at the boundary; a JSON float is rejected there, because `129.90` as a number is `129.89999999999998` in transit (ADR-005). `"129,90"` is accepted — Excel writes Turkish decimals with a comma |
| 15 | The retry lesson was applied BEFORE it could be paid for a second time | **Applied.** ADR-075 cost 29,074 attempts and ~155,000 duplicate failure rows overnight because a rejected catalogue row escaped to the chunk job, which Filament retried without a `$tries` or `$backoff`. An unknown barcode in a seller's feed is ORDINARY, so `OfferImporter` translates the domain refusal into `RowImportFailedException` — recorded per row, job never fails — and `OfferImportChunk` adds the fence anyway (`$tries = 3`, backoff 30/120/300, window ten minutes). **A test caught a boundary slip while this was being written**: the importer looked an offer up with `whereHas('variant', …)`, and an `Offer` has no such relation and must not grow one — the variant is Catalog's model and the offer carries `variant_uuid` as a plain string precisely so Offer imports nothing (ADR-042) |
| 19 | ADR-081–084 — Loyalty (customer points): standalone module, compute-on-read ledger, three earn events, operator-set rates, platform-funded redemption | **Design approved (2026-08-15, not yet built).** The platform gains customer points. A standalone `Loyalty` module (imports no module, like Payment/Offer/Order) keeps an **append-only ledger** with the **balance computed on read** — same stance as the buy box (ADR-045), rating average (ADR-069) and seller ledger (ADR-062); a reversal is a new row, never an edit. Earned three ways by class-string listeners: **signup** (once), **published review** (`ReviewPublished`, not on submit — one-per-line already caps it, ADR-067), and **purchase** — written only when a delivered seller-order passes its **return window** un-returned (`delivered_at + return_days`, a daily sweep mirroring auto-payout timing), on the KDV-included really-paid TL **excluding any part paid with points**, so nothing is ever clawed back and points can't feed themselves. Rates and point value are **operator `settings()`** on one audited Filament page (default **5% back**); a point is an **integer count** (ADR-005's minor-units rule does not reach it), the redemption value a **DECIMAL rate**. **Phase 2** adds redemption as a **platform-funded checkout discount** through a Core `LoyaltyContract` command port (hold → commit → release) — the seller payout is untouched, and a refund re-credits the spent points. **Phased**: P1 = earn + balance + admin + storefront display; P2 = redemption. Spec: `docs/modules/Loyalty.md`. Work order: `BUILD_LOYALTY_P1.md`. |

---

END OF FILE
