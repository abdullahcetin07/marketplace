# Notifications

**Sprint 1 ships infrastructure, not providers.** Database and mail work today.
SMS and push have channels, jobs, contracts and enum cases — and no driver,
because choosing one is a commercial decision that has not been made.

---

## Channels

| Channel | State | Queue | Opt-outable |
|---|---|---|---|
| `database` | working | `default` | **no** — it is the in-app inbox |
| `mail` | working | `notifications` | yes |
| `sms` | channel only | `notifications` | yes |
| `push` | channel only | `default` | yes |
| `broadcast` | channel only | `default` | yes |

Database notifications cannot be switched off. A user who muted everything must
still have a record of what happened to their account.

---

## Writing one

```php
final class OrderShipped extends BaseNotification
{
    public function channels(): array
    {
        return [NotificationType::Database, NotificationType::Mail];
    }

    public function toMail(mixed $notifiable): MailMessage { ... }
    public function toArray(mixed $notifiable): array { ... }
}
```

`BaseNotification` is **platform infrastructure in Core** —
`App\Core\Application\Notifications\BaseNotification`, alongside `BaseAction` and
`BaseEvent` — not part of the Notification *module*. Every module extends the
Core base directly, so sending a notification never makes a module depend on the
Notification module (which owns delivery: preferences, the send jobs, the
drivers). This is what keeps module isolation intact without an exception.

`via()` is **final**. A subclass declares which channels it *wants*; the base
class resolves that against what is implemented and what the recipient has
muted. Writing `via()` by hand in each notification is how one ends up ignoring
preferences.

Unimplemented channels are filtered out silently — otherwise every notification
declaring SMS would start throwing in production the day someone adds one.

### Security alerts

```php
public function isSecurityAlert(): bool { return true; }
```

Ignores opt-out preferences entirely. A user must not be able to mute the
message telling them their password was changed.

---

## Preferences

`notification_preferences` is a **deny-list**. A missing row means "send it".

An allow-list would mean every new notification type reaches nobody until users
opt in, which they never do — the feature ships and silently does nothing.

`notification_type` null mutes the whole channel; a class name narrows the
opt-out to one notification, so a user can mute marketing email without muting
order updates. The narrow rule is checked first, so a per-type preference can
re-enable one message inside a muted channel.

```php
$user->hasOptedOutOf(NotificationType::Mail, OrderShipped::class);
```

Preferences are owned by their user — `NotificationPreferencePolicy` scopes
reads as well as writes, and not even an admin may change them.

---

## Backlog — security-alert recipient preferences

**Deferred; recorded so the seam is known before code accretes around it.**

Platform security alerts (Q6 — a detected account attack) are today routed by a
single permission, `security.receive_alerts`, held by Super Admin and Support.
`UserRepository::securityAlertRecipients()` returns the active holders. This is
the **first-level authorization gate** and scales from five admins to hundreds
without paging all of them.

When this module gains per-user preferences, they sit **behind** the permission,
never replace it. The routing order is:

```
Recipient holds security.receive_alerts?
  NO  → do not notify.
  YES → notification preference enabled?
          YES → send.
          NO  → skip.
```

Two invariants survive the change:

- The **permission stays the outer gate.** Preferences can only ever *narrow*
  the permitted set, never widen it — a user without the permission is never a
  recipient regardless of their preferences.
- The **owner of the attacked account is always notified.** That message is an
  `isSecurityAlert()` and ignores opt-out entirely (see above); it is not
  subject to this routing at all.

No ADR — this applies existing rules (permission-based authorization + the
security-alert opt-out exemption). @see docs/modules/Identity.md §Q6.

---

## Turning SMS on

One binding. Nothing else in the platform changes.

```php
// A provider's own service provider
$this->app->bind(SmsProvider::class, NetgsmProvider::class);
```

Implement `App\Modules\Notification\Domain\Contracts\SmsProvider`:

```php
public function send(string $to, string $message): string;  // returns message id
public function balance(): ?int;
```

`balance()` is surfaced on the admin dashboard so an operator finds out before
messages start failing, not after.

Until then, `SmsChannel` and `SendSmsJob` throw `ChannelNotImplemented`.

**Why throw rather than no-op.** A channel that silently discards messages is
the worst outcome — someone ships an SMS notification, sees no error, and
assumes it worked. Throwing means the job fails, lands in `failed_jobs`, and is
visible in Horizon.

The one exception: `sms.enabled = false` is a deliberate operator decision and
logs at debug instead.

Push is identical in shape. Device tokens are **not** stored yet — that needs a
mobile client, and a token table with no client is dead schema.
`routeNotificationForPush()` is the seam.

---

## Queueing

`BaseNotification` implements `ShouldQueue` — notifications are never worth
blocking a request for. `viaQueues()` routes each channel to the queue its
latency profile deserves.

`notifications` is Horizon's highest-priority queue precisely because a password
reset is a user waiting. @see [queues.md](queues.md).

3 tries with exponential backoff. `SendSmsJob` overrides to 2 — SMS costs money
per attempt, and a gateway that rejected a message twice will reject it again.

---

## Locale

Queued notifications render in the **recipient's** language, not the language of
whoever triggered them. `User::preferredLocale()` implements Laravel's
`HasLocalePreference` contract by name.

`SendEmailJob` captures the locale at dispatch and applies it at send time —
without that, mail renders in whatever locale the worker happened to be in.

---

## SendEmailJob

Most mail should **not** use it. Laravel notifications already queue themselves
and `BaseNotification` routes them correctly. `SendEmailJob` exists for the
cases notifications do not cover — a one-off Mailable, a scheduled digest built
outside a notification.

```php
SendEmailJob::toUser($user, new InvoiceMail($invoice));
```
