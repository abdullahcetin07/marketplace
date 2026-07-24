# config

Standard Laravel configuration, plus two files of our own.

| File | Notable decision |
|---|---|
| `marketplace.php` | **Ours.** Currency, country, role *names*, media disks, rate limits, 2FA switch |
| `opensearch.php` | **Ours.** Connection + the Turkish analysis chain |
| `app.php` | Europe/Istanbul, locale `tr`, fallback `en` |
| `auth.php` | Three session guards over three scoped models + Sanctum |
| `database.php` | PostgreSQL; Redis split across 4 logical DBs |
| `cache.php` | Redis — the only store supporting the tags `BaseService` needs |
| `queue.php` | `retry_after` 180 s must exceed `BaseJob`'s 120 s timeout |
| `session.php` | Redis, encrypted, httpOnly, `same_site=lax` |
| `filesystems.php` | `s3` public + `s3-private` on a **separate bucket** |
| `logging.php` | Four channels: daily, audit (365d), activity, errors |
| `permission.php` | Wildcards **disabled** |
| `scout.php` | `opensearch` driver, queued, `after_commit` |
| `horizon.php` | Four queues with separate worker pools |
| `sanctum.php` | Stateful SPA mode, 7-day expiry, `mos_` token prefix |
| `cors.php` | Explicit origins, never `*`; credentials required |
| `marketplace.security.retention` | Audit 730d / activity 365d / login attempts 180d, pruned nightly |

Every file carries inline comments explaining the non-obvious values. Read them
before changing anything — several are load-bearing:

- `queue.retry_after` **must** exceed the longest job timeout, or a slow job is
  executed twice.
- `cache` and `queue` **must not** share a Redis database, or `cache:clear`
  deletes pending jobs.
- `cors.allowed_origins` cannot be `*` — browsers reject it with credentials, so
  a wildcard breaks login rather than loosening it.
- `opensearch.ssl_verification` must be `true` in production.

---

## `marketplace.php`

Domain settings that are not Laravel's business, kept together so opening a new
market is one file.

`roles` is the only place role names are written down. Nothing anywhere
references a role id. See
[docs/authorization.md](../docs/authorization.md).

---

## Environment separation

| Environment | Config source |
|---|---|
| Development | `.env` from `.env.example` |
| Testing | `.env.testing` — **committed**, no secrets, identical for everyone |
| Production | Injected by the orchestrator; caches baked at image build |

`.env.testing` is in version control on purpose: it guarantees every developer
and CI runner runs the suite against identical configuration.
