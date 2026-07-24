# MarketplaceOS

Enterprise multi-vendor marketplace platform.

**Status: Sprint 1 complete — the Foundation module group.**

Foundation is a **module group, not a module** (ADR-002). Seven modules:
Identity, Localization, Settings, Audit, Activity, Media, Notification.

No business modules exist. Organizations, stores, products, offers, orders and
payments arrive in later sprints.

---

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 / PHP 8.4 |
| Admin & seller panels | FilamentPHP 3 |
| Storefront | Next.js (separate repository) |
| Database | PostgreSQL 17 |
| Cache / queue / session | Redis 7 |
| Queue supervision | Laravel Horizon |
| Search | OpenSearch 2 (via Laravel Scout) |
| Object storage | S3-compatible (MinIO locally) |
| Auth | Sanctum + three independent session guards |
| Permissions | spatie/laravel-permission |

Locale **tr** (fallback **en**), timezone **Europe/Istanbul**, currency **TRY**.

---

## Getting started

Requires Docker and Docker Compose. Nothing else — no PHP, Composer or
PostgreSQL on your machine.

```bash
make install
```

That builds the images, installs dependencies, generates an app key, migrates,
seeds roles and permissions, and prints the URLs. Then create your first admin:

```bash
make admin
```

| Surface | URL |
|---|---|
| API | http://localhost:8080/api/v1 |
| Admin panel | http://localhost:8080/admin |
| Seller panel | http://localhost:8080/seller |
| Horizon | http://localhost:8080/admin/horizon |
| Mailpit | http://localhost:8025 |
| MinIO console | http://localhost:9001 |

`make help` lists every command.

---

## Project layout

```
app/
  Core/                    Framework-level foundation, shared by all modules
    Domain/                DTOs, events, exceptions, contracts — no framework
    Application/           Services, actions, jobs — orchestration
    Infrastructure/        Repositories, observers, search — persistence
    Presentation/          Controllers, policies, requests, resources, middleware
  Shared/                  Enums, traits, rules, helpers used everywhere
  Modules/                 The seven Foundation modules
    Identity/              Sessions, devices, login history, 2FA, auth flow
    Localization/          Languages, countries, currencies, timezones, translations
    Settings/              Business configuration, typed and cached
    Audit/                 Field-level record history (append-only)
    Activity/              User timeline (append-only)
    Media/                 Upload validation, optimisation, deletion
    Notification/          Channels, preferences, queued delivery
  Models/                  User + the three actor subclasses
  Providers/               Service providers, incl. both Filament panels

database/
  migrations/              Core schema: users, cache, jobs, permissions, media
  Modules/{Module}/        migrations/ Factories/ Seeders/ per module
  seeders/                 Roles and permissions; no demo data
  factories/               One factory per actor type

tests/
  Unit/                    No database
  Feature/                 Full application, database-backed
  Architecture/            Rules that keep the layering honest
  Modules/{Module}/        Per-module Unit and Feature suites

docs/                      Architecture decisions and operational guides
docker/                    PHP, nginx and entrypoint configuration
```

Every directory has its own `README.md` explaining what belongs in it.

---

## Quality gates

The four checks CI runs, all available locally:

```bash
make check
```

| Command | What it does |
|---|---|
| `make lint` | Laravel Pint, strict types enforced |
| `make analyse` | PHPStan level 6 with Larastan |
| `make test` | Pest — unit, feature and architecture |
| `make coverage` | Same, failing below 70% |

Architecture tests are not decoration. They fail the build if the Domain layer
imports Eloquent, if a module reaches into another module directly, or if a
`dd()` survives review. See `tests/Architecture/`.

---

## Documentation

| Document | Covers |
|---|---|
| [**Architecture Decision Record**](docs/Architecture_Decision_Record.md) | **Every approved decision.** Outranks all documents below |
| [Architecture](docs/001_Architecture.md) | Layering, module boundaries, decisions, costs, **amendment log** |
| [Coding Standards](docs/002_Coding_Standards.md) | SOLID, strict types, services, DTOs, size limits |
| [Database Standards](docs/003_Database_Standards.md) | Keys, columns, lookup tables, money, cascades, indexes |
| [Naming Conventions](docs/004_Naming_Conventions.md) | Classes, tables, enums, routes, JSON casing |
| [API Standards](docs/005_API_Standards.md) | Versioning, envelopes, pagination, status codes |
| [Foundation module group](docs/modules/Foundation.md) | The seven Foundation modules |
| [Authentication](docs/authentication.md) | Three guards, login flow, sessions, devices, 2FA |
| [Authorization](docs/authorization.md) | Dynamic permissions, nine roles, never by id |
| [Localization](docs/localization.md) | Languages, currencies, countries, translations, money |
| [Settings](docs/settings.md) | Typed values, groups, caching, encryption |
| [Audit & Activity](docs/audit.md) | The two trails, immutability, retention |
| [Notifications](docs/notifications.md) | Channels, preferences, wiring a provider |
| [Media](docs/media.md) | S3 disks, collections, conversions |
| [Modules](docs/modules.md) | How to add a business module |
| [Testing](docs/testing.md) | Suite layout, what belongs where |
| [Performance](docs/performance.md) | Strict mode, eager loading, caching |
| [Security](docs/security.md) | CSRF, rate limits, password policy, 2FA |
| [Logging](docs/logging.md) | Four channels and what each answers |
| [Queues](docs/queues.md) | Horizon topology, job defaults |
| [Search](docs/search.md) | OpenSearch engine, Turkish analysis |
| [Deployment](docs/deployment.md) | Image build, migration strategy, rollout |

> **Sprint prompts never override documentation.** Precedence: `CLAUDE.md` →
> Architecture Decision Record → Architecture → Database → Coding → Naming →
> API → module specs. Raise any conflict and get an explicit amendment first.

---

## Conventions

- `declare(strict_types=1)` in every file — enforced by Pint and an arch test.
- Money is an **integer of minor units**. Never a float. See the `Currency` model.
- Primary keys are **BIGINT**; public identifiers are **UUIDs**. Every foreign
  key references the bigint; the internal id never leaves the application.
- Timestamps are stored **UTC** (`timestamptz`), rendered Europe/Istanbul.
- Roles are referenced **by name**, never by id.
- Cross-module communication happens through **domain events**, not direct calls.
- **Enum or table?** If handling a new case needs code, it is an enum. If an
  operator must enable it without a release, it is a table.
- Audit and activity records are **append-only** — the models refuse updates.

---

## Licence

Proprietary. See [LICENSE](LICENSE).
