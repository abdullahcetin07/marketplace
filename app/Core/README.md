# app/Core

The foundation every module builds on. Contains **no business logic** — nothing
here knows what a product or an order is.

---

## Layers

Dependencies point **inward only**.

```
Presentation  →  Application  →  Domain  ←  Infrastructure
```

| Layer | Holds | May depend on |
|---|---|---|
| `Domain/` | `BaseDTO`, `BaseEvent`, `BaseException`, `RepositoryContract` | nothing |
| `Application/` | `BaseService`, `BaseAction`, `BaseJob` | Domain |
| `Infrastructure/` | `BaseRepository`, `BaseObserver`, `OpenSearchEngine` | Domain |
| `Presentation/` | `BaseController`, `BasePolicy`, `BaseRequest`, `BaseResource` | Application, Domain |

Infrastructure *implements* interfaces that Domain *declares*. That inversion is
what lets a service be unit-tested against an in-memory fake instead of a
database.

Enforced by `tests/Architecture/LayeringTest.php`.

---

## The base classes

| Class | Purpose | Key decision |
|---|---|---|
| `BaseDTO` | Immutable data across boundaries | Hydrates from camelCase *and* snake_case; casts enums and nested DTOs |
| `BaseEvent` | Domain event | Carries a correlation id; audited automatically |
| `BaseException` | Expected domain failure | `$reportable` defaults to **false** |
| `BaseService` | Orchestration for one aggregate | Tag-scoped cache namespace |
| `BaseAction` | One atomic use case | Owns its transaction; `after()` runs post-commit |
| `BaseJob` | Queued work | 3 tries, backoff, 120 s timeout, `retryUntil` |
| `BaseRepository` | Query containment | `$with` is the N+1 defence |
| `BaseObserver` | Derived state | Business rules do **not** belong here |
| `BasePolicy` | Authorisation | Permissions, never roles; ownership layered on top |
| `BaseController` | API entry | One response envelope everywhere |
| `BaseRequest` | Trust boundary | `authorize()` defaults to **false** |
| `BaseResource` | API output | UUIDs only; internal ids never leave |

Each class documents its own reasoning in its docblock.

---

## Action vs service

| | Transaction | Public methods | Name |
|---|---|---|---|
| Action | owns one | `handle()` | verb + noun — `ApproveStoreAction` |
| Service | none of its own | several | aggregate — `StoreService` |

If you cannot name it with one verb and one noun, it is a service that calls
several actions.

---

See [docs/001_Architecture.md](../../docs/001_Architecture.md).
