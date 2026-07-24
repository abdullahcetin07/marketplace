# Core/Infrastructure

**How it is stored and found.** Implements the ports Domain declares.

| Directory | Holds |
|---|---|
| `Repositories/` | `BaseRepository` — Eloquent implementation of `RepositoryContract` |
| `Observers/` | `BaseObserver` — derived state on model lifecycle |
| `Search/` | `OpenSearchEngine`, `SearchIndexingFailed` |
| `Models/` | Shared model infrastructure (currently empty) |

---

## Repositories

Not to abstract the ORM — PostgreSQL is not going anywhere. To **contain query
vocabulary**, so "active offers" cannot come to mean three different things in
three files.

`$with` is the primary N+1 defence. Because strict mode makes lazy loading
throw, the relations a module always needs are declared once here rather than
being rediscovered by whoever hits the exception next.

`query()` applies eager loads; `newQuery()` deliberately does not, so
`exists()` and `count()` do not load relations they will never read.

`cursor()` is `lazyById(500)` — use it for exports and back-fills. Never `all()`
on a marketplace table.

---

## Observers

**Belongs here:** derived state that must hold no matter how the model was
written — cache invalidation, search reindexing, denormalised counters, audit
stamps.

**Does not belong here:** business rules. An observer fires on seeders, imports,
admin edits and tinker sessions alike, and its failure modes are invisible at
the call site. If a rule can *reject* a write, it belongs in an action where the
caller can see and handle the refusal.

`withoutObserving()` suppresses side effects for bulk operations — reindex once
at the end rather than per row.

---

## Search

`OpenSearchEngine` is hand-written against the official client rather than
depending on a community Scout bridge. Reasoning, plus the Turkish analysis
configuration that makes results correct:
[docs/search.md](../../../docs/search.md).
