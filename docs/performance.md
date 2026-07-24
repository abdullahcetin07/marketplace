# Performance

The settings here are the ones that are almost impossible to retrofit. Enabled
in Sprint 0 for that reason.

---

## Strict mode

`AppServiceProvider::configureModels()` calls `Model::shouldBeStrict()`, which
switches on three protections at once:

| Protection | Catches |
|---|---|
| `preventLazyLoading` | N+1 queries — accessing an unloaded relation throws |
| `preventSilentlyDiscardingAttributes` | Assigning an unfillable attribute (the "it looked like it saved" bug) |
| `preventAccessingMissingAttributes` | Reading a column that was never selected |

**Development and CI: throws. Production: logs.**

That asymmetry is deliberate. A missed eager-load should fail the developer's
request, not a customer's checkout. In production the violation is written to
the `errors` channel with the model and relation name, so it is fixed on the
next deploy rather than experienced by a user.

Turning this on later is not realistic — it means fixing hundreds of latent N+1
queries simultaneously, which never gets scheduled. Turning it on now means each
one is caught by whoever writes it.

---

## Eager loading

Because lazy loading throws, relations must be declared. The place to declare
them is the repository:

```php
final class StoreRepository extends BaseRepository
{
    protected array $with = ['owner', 'address'];
    protected array $withCount = ['offers'];
}
```

This is the point of `BaseRepository::$with` — the relations a module *always*
needs are declared once, rather than being rediscovered by whoever hits the
exception next.

`BaseRepository::query()` applies them; `newQuery()` deliberately does not, so
`exists()` and `count()` do not load relations they will never read.

---

## Query budgets

Two guards in `AppServiceProvider::configureDatabase()`:

- **Per query, >500 ms** (non-production): logged as a slow query.
- **Per request, >2000 ms cumulative**: logged to `errors` in every environment.

The cumulative budget matters more. A page issuing 400 fast queries is broken
even though no individual query is slow.

In tests, use the `toRunQueries()` expectation to pin a specific budget.

---

## Pagination ceilings

`?per_page=100000` is a free denial-of-service against the database. Both
entry points clamp:

- `BaseController::perPage()` — clamps to `marketplace.pagination.max_per_page`
- `BaseRepository::paginate()` — `min($perPage, 100)`

Two independent clamps, because a Filament resource does not go through the
controller.

For bulk work, use `BaseRepository::cursor()`, which is `lazyById(500)` — never
`all()` on a marketplace table.

---

## Caching

Redis, with **tag-scoped invalidation**:

```php
$this->remember('active_stores', fn () => ...);   // svc:store_service:active_stores
$this->flushCache();                              // only this service's entries
```

`BaseService::cachePrefix()` namespaces every key to the service that wrote it,
so `flushCache()` can never clear another service's entries. This requires a
taggable store, which is why `config/cache.php` pins Redis and notes that the
`database` store will fail loudly on `flushCache()`.

Redis is split across four logical databases (`config/database.php`) so a cache
flush cannot wipe the queue:

| db | Purpose |
|---|---|
| 0 | locks, default |
| 1 | cache (flushable) |
| 2 | queues and job payloads |
| 3 | sessions |

Never point cache and queue at the same database — `cache:clear` would delete
pending jobs.

---

## Queues

Four queues with different characteristics, so one cannot starve another. See
[queues.md](queues.md).

The rule: nothing a user is waiting on should sit behind a bulk import.

---

## Search indexing

`SCOUT_QUEUE=true` in every environment except tests. Indexing synchronously
puts an HTTP round-trip to OpenSearch inside the user's save request and makes a
cluster hiccup look like a broken save.

`scout.after_commit = true` so a rolled-back save cannot leave a document in the
index.

---

## OPcache

Production only, `validate_timestamps=0` — PHP stops `stat()`-ing every file on
every request.

**Consequence:** a deploy MUST restart the container. Changed files are
otherwise ignored entirely. That is the correct trade for an immutable-image
deployment, and it is why config, route and event caches are built at image
build time in the Dockerfile rather than at first request.

JIT is on (tracing, 64 MB). Modest for a request-response workload — most time
is I/O, not PHP — but free once the buffer is sized.

Preloading is deliberately **not** configured: fragile across Laravel versions,
and a stale preloaded class is extremely hard to diagnose.

---

## PHP-FPM

`pm = dynamic`, 4 start / 2 min / 8 max spare, ceiling 20.

`pm.max_requests = 500` recycles workers periodically — the cheapest insurance
against a slow leak in a long-lived process. Horizon does the same via
`memory_limit = 128`.

---

## Immutable dates

`Date::use(CarbonImmutable::class)`. With mutable Carbon,
`$order->created_at->addDays(7)` silently mutates the model's attribute. That is
shared-mutable-state, and it produces bugs that are very hard to trace back to
their cause.
