# Core/Domain

**What the business is.** The innermost layer — depends on nothing.

No Eloquent. No `Request`. No `DB` facade. No HTTP. Enforced by
`tests/Architecture/LayeringTest.php`.

| Directory | Holds |
|---|---|
| `Contracts/` | Ports the outer layers implement — `RepositoryContract` |
| `DataTransferObjects/` | `BaseDTO` — immutable data across boundaries |
| `Events/` | `BaseEvent` — past-tense facts, the cross-module channel |
| `Exceptions/` | `BaseException` — expected domain failures |

---

## Why the dependency rule matters

`RepositoryContract` lives here while `BaseRepository` lives in Infrastructure.
That inversion is the entire point: a domain service type-hints the interface
and never mentions Eloquent, so business rules can be unit-tested against an
in-memory fake without booting a database.

Two documented exceptions in the arch test:
`RepositoryContract` names `Model` in its signatures (it *is* the seam), and
`BaseException` sees `Request` because it renders itself into an HTTP response.

---

## Conventions

**DTOs** are readonly promoted constructor properties and nothing else. They are
not validated — that is the `FormRequest`'s job. `fromArray()` accepts both
camelCase and snake_case and casts enum and nested-DTO parameters.

**Events** are past tense: `StoreApproved`, never `ApproveStore`. Every one
carries a correlation id and is audited automatically unless `shouldAudit()`
returns false.

**Exceptions** default to `$reportable = false`. A store not being approved is a
business outcome, not an incident. Genuine bugs should keep throwing native
exceptions so they stay loud.
