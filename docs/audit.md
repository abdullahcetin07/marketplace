# Audit and Activity

Two tables, two questions, two retention periods. Reasoning:
[001_Architecture.md §15](001_Architecture.md).

| | `audit_entries` | `activity_entries` |
|---|---|---|
| Answers | "What **forensic event** occurred?" | "What did **this user** do?" |
| Shape | `event_type` + `severity` + diff or metadata | one readable sentence |
| Read by | a lawyer or a SIEM, during an incident | a customer, on their security page |
| Retention | 730 days | 365 days |
| Written by | `Auditable` trait **and** `AuditLogger` | `ActivityLogger` |

A password change produces **both** — an activity entry and an audit entry on
the user row. That duplication is deliberate: the activity entry survives the
audit window.

**Audit is the platform's forensic event store (ADR-027).** Model changes are
one category. A row carries a generic `event_type`
(`App\Modules\Audit\Domain\Enums\AuditEventType` — `MODEL_*`, `SECURITY_*`, and
governance seams) and a `severity` that is **independent** of it
(`AuditSeverity`: `INFO · NOTICE · WARNING · HIGH · CRITICAL`, mapped onto
PSR/syslog levels for SIEM export). A security event has no model diff, so
`event`, `auditable_type` and `auditable_id` are nullable and its context lives
in a `metadata` jsonb column instead of `old_values`/`new_values`.

---

## Audit

Opt a model in:

```php
final class Setting extends Model
{
    use \App\Modules\Audit\Domain\Concerns\Auditable;

    protected array $auditExclude = ['cached_value'];
}
```

Captures `created`, `updated`, `deleted`, `restored` with the causer, IP,
browser, platform, URL and correlation id.

Request facts arrive as an **explicit context** (ADR-019). `Auditable` never
calls `request()`.

`CaptureAuditContext` middleware (Presentation) builds an
`App\Core\Domain\Context\AuditContext` — a plain value object — and pushes it
in. The trait reads `AuditContext::current()`.

This also fixed a correctness bug. `request()` is meaningless in a queue
worker, a console command or a seeder, where it silently yielded nulls that
looked like missing data. Outside HTTP the context is now
`AuditContext::system()` and records its `origin`, so "no IP because this was a
console command" is distinguishable from "no IP recorded".

The middleware clears the context after each request, so a long-lived worker
cannot leak one request's context into the next.

**Only changed attributes are stored.** An idempotent save writes nothing —
otherwise every no-op `save()` pollutes the trail.

**Never recorded, for any model:** `password`, `remember_token`,
`two_factor_secret`, `two_factor_recovery_codes`. Globally excluded and not
re-enableable per model. An audit trail containing password hashes is a
credential store with a long retention policy and a permissive read scope.

### Immutability

`AuditEntry` returns `false` from `updating` and `deleting`. Enforced by the
model, not by a policy someone can bypass from a console command. An editable
audit trail is not an audit trail.

Retention pruning bypasses the model with a query-builder delete
(`routes/console.php`).

### Querying

```php
$setting->audits;                                  // this record's history
AuditEntry::query()->causedBy($admin)->get();      // this person's changes
AuditEntry::query()->forCorrelation($id)->get();   // this request's changes
$entry->diff();                                    // ['field' => ['old','new']]
```

`old_values` and `new_values` are `jsonb`, so "every price change on this
record" is an indexed query rather than a full scan.

### Suppression

```php
Setting::withoutAuditing(fn () => $importer->run());
```

For imports and back-fills where a row-per-record trail is noise, not evidence.
Use sparingly — the point of the trail is that it is complete.

### Security events

Events with no model diff — a detected attack, and later a permission grant or
a store transfer — are written through `AuditLogger`, not the trait:

```php
app(AuditLogger::class)->record(
    type: AuditEventType::SecurityBruteForce,
    severity: AuditSeverity::High,
    auditable: $targetedUser,          // null when the address has no account
    metadata: ['email' => $email, 'failure_count' => 12, 'distinct_ips' => 4],
);

AuditEntry::query()->security()->get();                         // the SIEM feed
AuditEntry::query()->atLeastSeverity(AuditSeverity::High)->get();
```

**Audit subscribes; the producer does not know it exists.** Identity announces
`SuspiciousLoginDetected`; `Audit\Application\Listeners\RecordSecurityAudit`
maps the threat to a type and severity and writes the row. This is the same
consumer direction as Activity — Audit imports the producer's `Domain\Events`
and nothing else, enforced by `LayeringTest`. Adding a new `SECURITY_*` producer
means adding a subscription, never Audit reaching into another module.

**Secret-column changes come through events, not the diff.** Enabling or
disabling 2FA changes only `two_factor_*` columns, which are globally excluded —
so the `Auditable` trait writes nothing. The forensic record instead comes from
`TwoFactorEnabled` / `TwoFactorDisabled` (`SECURITY_TWO_FACTOR_*`), High when an
administrator cleared someone's 2FA. Both the trait path and the event path land
in the same table: one forensic store, two ways in.

**Reason travels on the audit context.** An admin action attaches WHY via
`AuditContext::withReasonFor(...)`; it lands in `metadata.reason` on whichever
entry the action produces (a diff entry for an update, an event entry for a 2FA
disable). Self-service changes carry no reason and the key is simply absent.

**The password-reset lifecycle is a forensic timeline.** `password` is
secret-excluded from the diff trail, so — like 2FA — every stage is recorded
from its event:

```
SECURITY_PASSWORD_RESET_ISSUED      (Notice)  ← reset link issued (self or admin)
        ↓
SECURITY_PASSWORD_RESET_COMPLETED   (Notice)  ← PasswordChanged, viaReset
   or SECURITY_PASSWORD_CHANGED     (Notice)  ← PasswordChanged, deliberate
        ↓
SECURITY_SESSIONS_REVOKED           (Info)    ← the cascade that follows
```

Each carries actor (causer), target user, reason (when an admin gave one), IP,
user-agent, correlation id and timestamp — the correlation id is what stitches
the four rows into one incident.

---

## Activity

```php
app(ActivityLogger::class)->log(ActivityType::PasswordChanged, $user);
```

A service rather than direct `create()` calls, because every entry needs the
same request context (IP, browser, platform, correlation id) and gathering it
at each call site guarantees some call sites forget.

`$user` defaults to the current actor. Pass it explicitly for actions taken
**on** a user **by** someone else — an admin changing a seller's role belongs on
the seller's timeline, not the admin's.

### Identity events feed it

`RecordIdentityActivity` subscribes to Identity's domain events.
**That class is the module boundary.** Identity does not know Activity exists;
it announces what happened and stops.

| Event | Activity type |
|---|---|
| `UserLoggedIn` | `Login` (with `new_device`) |
| `UserLoggedOut` | `Logout` |
| `PasswordChanged` | `PasswordChanged` or `PasswordReset` |
| `TwoFactorEnabled` / `Disabled` | matching type |
| `SessionRevoked` | `SessionRevoked` |
| `UserCreated` | `ProfileUpdated` + "account created" |

Not queued: a user checking "was that me?" straight after a login must see it.

### User-visible vs internal

`ActivityType::userVisible()` is a **strict subset**, applied as a query scope —
not merely hidden by the view. Internal entries (an admin changing this user's
permissions) are excluded from `scopeUserVisible()`.

Failed logins **are** user-visible, deliberately. A user noticing attempts they
did not make is the cheapest intrusion detection available.

`ActivityType::securitySensitive()` drives owner notifications and bypasses
opt-out preferences.

---

## Login attempts

A third table, in the Identity module: `login_attempts`. Every attempt,
successful or not.

`user_id` is nullable because an attempt against a non-existent address must
still be recorded — that is exactly what enumeration looks like.

**Never stores the attempted password, not even hashed.**

```php
LoginAttempt::recentFailuresFor($email, $guard);   // credential stuffing
LoginAttempt::distinctIpsFor($email);              // one user vs a botnet
AuthService::classifyThreat($email, $guard);       // null | BruteForce | CredentialStuffing
```

Retention 180 days — this exists for attack detection, which operates on hours.

---

## Authorization

| Permission | Held by |
|---|---|
| `audit.view_any`, `audit.view`, `audit.export` | Admin, Finance |
| `activity.view` | all three actor types — **their own** |
| `activity.view_any` | Admin, Support |

`ActivityEntryPolicy` allows a user to read their own timeline without
`activity.view_any`; the controller scopes the query. `AuditEntryPolicy` does
**not** extend `BasePolicy` — there is no create/update/delete to inherit, and
offering them would imply an editable trail.

---

## Retention

Pruned nightly, separately, in `routes/console.php`:

| Table | Days | Config |
|---|---|---|
| `audit_entries` | 730 | `marketplace.security.retention.audit_days` |
| `activity_entries` | 365 | `…activity_days` |
| `login_attempts` | 180 | `…login_attempt_days` |

Different periods because they carry different legal weight. Merging them would
force the shortest retention onto the most significant evidence.
