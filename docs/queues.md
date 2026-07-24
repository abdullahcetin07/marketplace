# Queues

Redis, supervised by Horizon. Four queues, deliberately separated.

| Queue | Character | Workers (prod) | Timeout | Priority |
|---|---|---|---|---|
| `notifications` | short, latency-sensitive | 2–20 (shared) | 120 s | highest |
| `default` | general domain work | 2–20 (shared) | 120 s | high |
| `search` | bursty, nobody waiting | 1–8 | 180 s | low (`nice 5`) |
| `media` | slow, CPU-heavy | 1–4 | 600 s | lowest (`nice 10`) |

**Why separate them.** A single shared queue lets one bulk product import —
which enqueues thousands of indexing jobs — delay every password reset email on
the platform. Nothing a user is actively waiting on should sit behind bulk work.

`media` gets few workers and the lowest priority because image conversion is
CPU-bound and would otherwise monopolise the box.

---

## BaseJob defaults

Laravel's defaults are wrong for a marketplace under load. `BaseJob` fixes
three things, and every job inherits them:

| Setting | Value | Without it |
|---|---|---|
| `$tries` | 3 | Unbounded retries turn a downstream outage into a poison-pill storm |
| `$timeout` | 120 s | One wedged HTTP call ties up a worker indefinitely |
| `backoff()` | 10 / 60 / 300 s | Immediate retries hammer an already-failing dependency |
| `retryUntil()` | +1 hour | A job keeps being requeued long after its data is stale |
| `$deleteWhenMissingModels` | `true` | A job fails because a record was deleted — the work is moot, not broken |

```php
final class ReindexStoreJob extends BaseJob
{
    public function __construct(public readonly int $storeId)
    {
        parent::__construct();   // required — sets the correlation id and queue
    }

    protected function queueName(): string
    {
        return 'search';
    }

    public function handle(StoreRepository $repository): void { ... }
}
```

`queueName()` must return a queue Horizon actually supervises. A typo means the
job is enqueued successfully and never picked up — a silent failure.

---

## retry_after must exceed the longest timeout

`config/queue.php` sets `retry_after = 180`, above `BaseJob`'s 120 s timeout.

If `retry_after` were lower, a slow job would be handed to a second worker while
the first attempt is still running — double execution, which for anything
touching money or stock is a correctness bug, not a performance one.

---

## after_commit

Both `queue.connections.redis.after_commit` and `scout.after_commit` are `true`.

Dispatching inside a transaction that later rolls back means a worker picks up a
job referencing a row that does not exist. `after_commit` defers the push until
the transaction succeeds.

`BaseAction::after()` exists for the same reason at the action level: it runs
*after* commit, and is the only safe place to dispatch side effects.

---

## Failed jobs go to PostgreSQL

`config/queue.php` → `failed.driver = database-uuids`.

A Redis eviction or flush would silently destroy the record of what failed —
precisely the data you need after an incident.

Pruned after 7 days (`routes/console.php`), by which point the failure is in the
errors log and the audit trail.

---

## Horizon

Dashboard at `/admin/horizon`, gated by the `system.horizon.view` **permission**,
not merely by authentication. Job payloads routinely contain customer email
addresses and order contents; an Editor with admin panel access has no business
reading them. See `HorizonServiceProvider::gate()`.

- `memory_limit = 128` MB per worker — the cheapest defence against a slow leak
  in a long-lived process.
- `prefix` is namespaced per environment, or staging metrics appear in
  production.
- Slack notifications on failure in production, if configured. Silence means a
  broken queue is discovered by a customer.

```bash
make horizon          # tail worker logs
make queue-restart    # graceful restart (horizon:terminate)
```

**Deploys must run `horizon:terminate`**, otherwise workers keep executing the
previous release's code against the new database schema.

---

## Local development

`horizon` and `scheduler` run as their own containers in `docker-compose.yml`.

Separate from the web container on purpose: a long-running worker must not be
restarted every time nginx reloads, and its memory profile is entirely
different. `stop_grace_period: 60s` lets in-flight jobs finish on shutdown.
