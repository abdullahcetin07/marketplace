# database

```
migrations/   Framework, identity and permission tables only
Modules/      Per-module migrations and factories (empty until Sprint 1)
seeders/      Roles and permissions. No demo data.
factories/    One per actor type
```

---

## Sprint 0 schema

| Table | Why it exists |
|---|---|
| `users` | Identity for all three actor types |
| `password_reset_tokens`, `sessions` | Framework auth |
| `cache`, `cache_locks` | Degraded-mode fallback; Redis is the real cache |
| `jobs`, `job_batches` | Queue fallback |
| `failed_jobs` | **Not** a fallback — always used, so a Redis flush cannot destroy failure records |
| `roles`, `permissions`, + 3 pivots | spatie/laravel-permission, guard-scoped |
| `personal_access_tokens` | Sanctum |
| `activity_log` | Field-level model history |
| `media` | Media library storage — see note below |

**No business tables.** No products, orders or stores. That is the sprint scope.

`media` is a documented deviation: `HasMedia` is part of the required foundation
and is inert without it. It carries no domain data.
See [docs/media.md](../docs/media.md).

---

## Conventions

- `timestampsTz()` / `softDeletesTz()` — timestamps stored UTC, rendered
  Europe/Istanbul.
- `uuid` unique on anything publicly addressable. Internal `id` never leaves the
  application.
- `jsonb`, not `json` — PostgreSQL can index into it.
- Enum-backed columns are `string`, cast on the model. The enum is the source of
  truth; the database only stores the value.
- The `users` unique index is `(type, email)`, not `email`. Reasoning:
  [docs/authentication.md](../docs/authentication.md).

---

## Seeding

```bash
make seed          # RolePermissionSeeder — idempotent, safe on every deploy
make permissions   # sync permissions from PermissionRegistry
make admin         # create the first admin INTERACTIVELY
```

There is no demo data. The foundation must be verifiable without fixtures, and
a seeder inventing products would contradict the sprint scope.

The first admin is never seeded — a seeded admin means every environment ships
with an account whose password is in version control.

---

## Migration ordering in production

Migrations run in a **single deploy job**, never in the container entrypoint.
Concurrent `migrate` across starting containers is a race that corrupts the
schema. See [docs/deployment.md](../docs/deployment.md).
