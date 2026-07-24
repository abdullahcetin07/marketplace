# Core/Application

**What the business does.** Orchestration. May depend on Domain; never on
Presentation.

| Directory | Holds |
|---|---|
| `Actions/` | `BaseAction` — one atomic use case, owns its transaction |
| `Services/` | `BaseService` — the API a module presents |
| `Jobs/` | `BaseJob` — queued work with safe defaults |

---

## Action vs service

| | Transaction | Public methods | Named |
|---|---|---|---|
| **Action** | owns one | `handle()` only | verb + noun — `ApproveStoreAction` |
| **Service** | none of its own | several | aggregate — `StoreService` |

**The test:** if you cannot name it with one verb and one noun, it is not an
action — make it a service that calls several actions.

**Why actions own the transaction rather than services:** a service wrapping
three actions holds locks for the duration of all three, including any HTTP
calls they make. Pushing the boundary down keeps transactions short, which is
the biggest single lever on write throughput under contention.

---

## `BaseAction::after()`

Runs **after commit**. It is the only safe place to dispatch side effects —
emails, webhooks, search indexing — because anything fired inside the
transaction still fires when the transaction rolls back.

---

## `BaseService` plumbing

- `remember()` / `flushCache()` — cache scoped to this service by tag, so a
  service can only ever invalidate its own entries.
- `log()` — writes to the service's channel with the class attached.
- `transaction()` — for the rare case where several actions must be atomic as a
  group.

Services contain no query building. That is the repository's job.

---

## `BaseJob`

3 tries, exponential backoff, 120 s timeout, abandoned after an hour, failures
logged to `errors` with full context. Laravel's defaults are wrong for a
marketplace under load — see [docs/queues.md](../../../docs/queues.md).

Subclasses must call `parent::__construct()`.
