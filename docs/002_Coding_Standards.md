# MarketplaceOS Coding Standards
Version: 1.1

Synchronized with the Architecture Decision Record (ADR-010, ADR-011).

---

# 1. Purpose

This document defines coding standards for MarketplaceOS.

Every PHP file, JavaScript file and TypeScript file must follow these rules.

This document sits at position 5 in the precedence chain (ADR-003). It is
outranked by `CLAUDE.md`, the ADR, `001_Architecture.md` and
`003_Database_Standards.md`. Where a rule below is narrowed by an ADR, the ADR
text is reproduced inline.

---

# 2. General Principles

Follow

- SOLID
- DRY
- KISS
- YAGNI
- Clean Architecture
- PSR-12

Readable code is preferred over clever code.

---

# 3. Language

Source Code

English

Comments

English

Commit Messages

English

UI

Turkish

---

# 4. Strict Types

Every PHP file must begin with

declare(strict_types=1);

---

# 5. Controllers

Controllers must be thin.

Allowed

- Receive Request
- Call Service
- Return Resource

Forbidden

- Business Logic
- Database Query
- Calculations
- Permission Logic
- Validation Logic

---

# 6. Services

Business logic belongs in Services.

Services must

- Be stateless
- Have one responsibility

## 6.1 Return types (ADR-010)

Services may return:

- DTO
- Value Object
- Domain Result

**REST APIs must never expose Eloquent models directly.** A controller serving
`/api/v1/*` returns an API Resource built from a DTO or a Domain Result.

**Presentation layers may use Eloquent models where appropriate** — Filament,
Console commands and the Admin UI. Filament resolves Eloquent models by design;
forcing DTO round-trips through the panels buys nothing and fights the
framework.

The boundary is therefore the **transport**, not the layer:

| Consumer | May receive Eloquent |
|---|---|
| REST API response | **no** |
| Filament resource / page | yes |
| Console command | yes |
| Another Service | prefer DTO |

---

# 7. Repositories

Repositories are responsible only for data persistence.

Forbidden

- Business Rules
- Validation
- Authorization

## 7.1 What a repository may return

A repository **may** use Eloquent internally. Callers **may not** depend on it.

Never return a `Builder`, a `QueryBuilder`, or anything that expects the caller
to keep querying. Return a concrete result: a model, a collection, a paginator
or a DTO.

Callers must never rely on lazy loading — strict mode makes it throw. Eager
loads are declared **on the repository**, once.

A repository that hands back a query builder has abstracted nothing.

See `001_Architecture.md` §13.1.

---

# 8. Form Requests

Validation must always use FormRequest.

Never validate inside Controllers.

---

# 9. API Resources

Always return Laravel API Resources.

Never expose Eloquent Models directly.

---

# 10. Dependency Injection

Always use constructor injection.

Never instantiate services with "new".

---

# 11. Models

Domain models may contain (ADR-011)

- Relationships
- Scopes
- Accessors
- Mutators
- Lightweight helper methods

**Business workflows belong to Services.**

The line: a method that answers a question about *this record alone* is a
helper and may live on the model. A method that orchestrates several records,
opens a transaction, dispatches events or calls another service is a workflow
and belongs in a Service or an Action.

| Belongs on the model | Belongs in a Service |
|---|---|
| `Currency::toMinor()` — a pure conversion | `AuthService::attempt()` — orchestrates, records, dispatches |
| `Setting::typedValue()` — casts its own column | `SettingsService::set()` — writes, invalidates cache, dispatches |
| `UserDevice::isTrusted()` — reads its own state | `SessionService::revokeAll()` — spans rows, opens a transaction |

---

# 12. Events

Important actions dispatch Events.

Never use Events for synchronous business logic.

---

# 13. Jobs

Heavy operations use Jobs.

Examples

- Email
- SMS
- Image Processing
- Search Indexing

---

# 14. DTO

Services accept DTOs.

Services return DTOs.

Never pass Request objects to Services.

Suffix: **`DTO`** (ADR-021) — `LoginDTO`, `CreateProductDTO`.

Location: `{Module}/Domain/DTOs/`.

"Data" is forbidden as a DTO class suffix. See `004_Naming_Conventions.md` §13.

---

# 15. Exceptions

Create custom exceptions.

Never throw generic Exception.

---

# 16. Configuration

Never hardcode

- URLs
- API Keys
- Timeouts
- Limits

Everything configurable.

---

# 17. Constants

Use Enums or Config.

Never use magic numbers.

---

# 18. Logging

Log

- Errors
- Warnings
- Security Events

Never log passwords or tokens.

---

# 19. Transactions

Use transactions only where required.

Keep transactions short.

---

# 20. Testing

Every Service requires

- Unit Test

Every API requires

- Feature Test

Every Module requires

- Architecture Test

---

# 21. Documentation

Every public class must have PHPDoc.

Complex methods require explanation.

---

# 22. Method Size

Maximum

50 lines

Refactor if larger.

---

# 23. Class Size

300 lines

**Review threshold, not a hard rule** (ADR-020).

A class exceeding 300 lines requires **architectural review and documented
justification**. It does not automatically require splitting.

## 23.1 Permanent exemptions

Recorded, with justification:

| Exemption | Example | Justification |
|---|---|---|
| Framework interface implementations | `OpenSearchEngine` (394) | Scout's `Engine` mandates 11 public methods. The interface is not ours. |
| Aggregate roots | `User` (477) | Length follows from approved decisions: STI (`docs/authentication.md`), composite email uniqueness (ADR-012), and identity sitting above the modules (`001_Architecture.md` §6). |

## 23.2 What remains STRICT

Line count tracks comprehensibility poorly at class level and well at method
level. These are hard limits, not thresholds:

- **Maximum 50 lines per method** (§22)
- **Maximum 7 constructor dependencies** (§24)
- **High cyclomatic complexity must be refactored** (§24.1)

Splitting a class into pieces that must be read together does not make it
simpler — it makes the complexity harder to see.

---

# 24. Constructor

Maximum

7 dependencies.

Otherwise refactor.

**Strict** (ADR-020). A constructor needing an eighth dependency is a class with
more than one responsibility.

---

# 24.1 Cyclomatic Complexity

High cyclomatic complexity must be refactored.

**Strict** (ADR-020).

Extract the branching into named methods, a state machine on an enum, or a
strategy. A method whose branches cannot be held in the head at once cannot be
reviewed, and cannot be tested to its edges.

---

# 25. Return Types

Always declare return types.

Never omit types.

---

# 26. Nullable Types

Prefer explicit nullable types.

Avoid mixed.

---

# 27. Collections

Prefer Laravel Collections.

Avoid raw arrays for business objects.

---

# 28. Static Methods

Allowed only for

- Helpers
- Factories
- Value Objects

---

# 29. Traits

Use Traits only for shared technical behavior.

Never hide business logic inside Traits.

---

# 30. Forbidden

Never

- Duplicate logic
- Hardcode IDs
- Hardcode commission values
- Query another module directly
- Use DB::table in business code
- Skip tests
- Ignore exceptions

## 30.1 Domain layer helpers (ADR-019)

The former blanket ban on "Facades inside Domain layer" is replaced by an
explicit list. **The rule covers global helper functions as well as Facade
classes** — a helper resolving the same container binding is the same
violation.

**Allowed in Domain**

| Helper | Why |
|---|---|
| `now()` | A clock reading. `travelTo()` controls it in tests, so it does not impede testability. |
| `config()` | Reads a static array. No I/O, no state. |

**Forbidden in Domain**

| Helper | Belongs to | Why |
|---|---|---|
| `cache()` | Infrastructure | Real I/O. Hidden in a model, it makes the class untestable in isolation. |
| `request()` | Presentation | Request state has no meaning in a queue worker, a console command or a seeder. |
| `encrypt()` / `decrypt()` | Infrastructure | Key material and a rotation failure mode. |

**Where the work goes instead**

- **Caching** → Repositories or dedicated Infrastructure services.
- **Encryption** → an Eloquent cast or an Infrastructure decorator.
- **Request access** → pass a context object in from Presentation; never pull
  it from the container inside Domain.

`auth()` is **not** forbidden (ADR-024) — it is the authenticated Identity
context, not infrastructure.

## 30.2 ORM metadata exception (ADR-023)

Eloquent is Active Record, so a Domain model must name some Infrastructure
classes for the ORM to work at all. Permitted **declaratively only**:

| Allowed | Forbidden |
|---|---|
| Custom cast in `casts()` | Calling a Service |
| Observer in `observe()` | Calling a Repository |
| Global scope in `addGlobalScope()` | Cache · HTTP · Mail · Queue · Crypt |

**Naming a class is metadata. Calling a method on it is a dependency.**

---

END OF FILE