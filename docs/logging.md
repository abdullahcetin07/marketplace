# Logging

Four channels, four different questions. Merging them would force the shortest
retention onto the most legally significant data.

| Channel | File | Retention | Answers |
|---|---|---|---|
| `daily` | `marketplaceos.log` | 30 days | "What was the application doing?" |
| `audit` | `audit.log` | **365 days** | "Who did what, to which record, when?" |
| `activity` | `activity.log` | 180 days | Field-level model diffs (mirror of the DB table) |
| `errors` | `errors.log` | 90 days | "What is broken right now?" |

`audit` gets a year because it answers dispute-resolution questions — *was this
price changed by the seller or by us?* — not debugging ones.

`errors` exists so an on-call engineer tails one small file instead of paging
through debug noise.

---

## Correlation ids

`App\Http\Middleware\AssignCorrelationId` runs first on every request. It:

1. Reuses an inbound `X-Request-Id` if it matches `^[A-Za-z0-9\-]{8,64}$`
   (so a trace spans the Next.js frontend and the API), otherwise generates one.
2. Binds it as `correlation_id` in the container.
3. Calls `Log::shareContext()` so **every** log line in the request carries it.
4. Echoes it back in the response header.

The inbound value is validated because a client must not be able to inject
arbitrary length or content into every log line.

It propagates automatically into:

- `BaseEvent::$correlationId` — every domain event
- `BaseJob::$correlationId` — every queued job, and its Horizon tag

So *"the customer says checkout failed at 14:32"* becomes one query rather than
a manual reconstruction across web logs, worker logs and the audit trail.

Read it anywhere with `correlation_id()`.

---

## What gets audited automatically

**Every domain event.** `EventServiceProvider` registers one wildcard listener
that writes any `BaseEvent` to the `audit` channel. Auditing is a property of
`BaseEvent`, so it is implemented once at that level rather than as a listener
per event. Individual events opt out with `shouldAudit(): false`.

**Every authentication outcome:**

| Event | Channel | Level |
|---|---|---|
| `Login` | audit | info — also stamps `last_login_at`/`last_login_ip` |
| `Failed` | audit | warning — logs the attempted **email**, never the password |
| `Lockout` | errors | warning |
| `Logout` | audit | info |

**Every authorisation denial** — `AuthServiceProvider`'s `Gate::after` hook, with
user, guard, ability and correlation id. Denials are otherwise invisible; the
user just sees a 403.

**Every permanent job failure** — `BaseJob::failed()` writes job class, queue,
attempts, correlation id and trace to `errors`.

---

## Activity log vs audit channel

Both exist because they answer different questions:

- **Activity log** (`spatie/laravel-activitylog`, `activity_log` table):
  *what changed on this record* — field-level before/after diffs, queryable via
  JSONB. Attached per model with `LogsActivity`.
- **Audit channel** (file): *what action was taken* — domain events, logins,
  denials. Survives a database restore-to-point-in-time.

You want both. The activity log tells you a price went from 100 to 90; the audit
channel tells you it happened because `OfferPriceChanged` fired during an admin
bulk action at 14:32 under correlation id `abc`.

`activity_log` grows faster than anything else in the schema — every price
change on every offer lands there. `activitylog:clean` runs nightly
(`routes/console.php`).

---

## Application code

```php
// From a service — channel comes from BaseService::$logChannel
$this->log('Store approved', ['store_id' => $store->id]);

// Explicitly
Log::channel('audit')->info('...', [...]);
```

`BaseObserver::log()` writes model context to `audit` automatically.

---

## Production

Set `LOG_CHANNEL=stderr` in containers so the orchestrator's log collector
receives everything. `LOG_STDERR_FORMATTER` can select a JSON formatter for
structured ingestion.

Do **not** rely on the file channels in a container — they are lost on restart.
They are the right default for local development and for a single-VM
deployment.

---

## Error tracking

Sentry is wired through `config/services.php` only. The SDK is installed per
environment so it never runs in the test suite; an **empty `SENTRY_LARAVEL_DSN`
disables reporting entirely**, which is the intended local default.

`send_default_pii` is `false`.

What reaches the tracker is controlled by `BaseException::$reportable`, which
defaults to **false**. Expected domain failures — store not approved, offer out
of stock — are not incidents and must not bury the real ones. Exceptions that
represent genuine data loss set it to `true`; see `SearchIndexingFailed`.
