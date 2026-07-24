# Documentation

Architecture decisions and operational guides for MarketplaceOS.

Documentation is **executable architecture** (ADR-018). Implementation must
never contradict approved documentation. Where ambiguity exists: stop, report,
wait for approval — never guess.

---

## Document precedence (ADR-003)

1. [`../CLAUDE.md`](../CLAUDE.md)
2. [`Architecture_Decision_Record.md`](Architecture_Decision_Record.md)
3. [`001_Architecture.md`](001_Architecture.md)
4. [`003_Database_Standards.md`](003_Database_Standards.md)
5. [`002_Coding_Standards.md`](002_Coding_Standards.md)
6. [`004_Naming_Conventions.md`](004_Naming_Conventions.md)
7. [`005_API_Standards.md`](005_API_Standards.md)
8. Module specifications

**Sprint prompts never override documentation.**

---

## Governing documents

| Document | Covers |
|---|---|
| [Architecture_Decision_Record.md](Architecture_Decision_Record.md) | **All approved decisions.** Outranks everything below until they are updated to match |
| [001_Architecture.md](001_Architecture.md) | Architecture style, layers, dependency rules, identifiers, money, enums vs lookups, amendment log |
| [002_Coding_Standards.md](002_Coding_Standards.md) | SOLID, strict types, controllers, services, DTOs, size limits |
| [003_Database_Standards.md](003_Database_Standards.md) | Keys, columns, lookup tables, money, cascades, indexes, migrations |
| [004_Naming_Conventions.md](004_Naming_Conventions.md) | Classes, tables, enums, routes, JSON casing |
| [005_API_Standards.md](005_API_Standards.md) | Versioning, envelopes, pagination, status codes, rate limits |

> `docs/architecture.md` was absorbed into `001_Architecture.md` and removed
> (ADR-001). Its decisions, costs and amendment history are carried there.

---

## Module specifications

| Document | Covers |
|---|---|
| [modules/Foundation.md](modules/Foundation.md) | The Foundation **module group** — seven modules (ADR-002) |
| [modules/Identity.md](modules/Identity.md) | Identity module specification and implementation plan |
| [modules.md](modules.md) | How to add a new business module |

---

## Foundation module guides

| Document | Module | Covers |
|---|---|---|
| [authentication.md](authentication.md) | Identity | Three guards, login flow, sessions, devices, 2FA |
| [authorization.md](authorization.md) | — | Dynamic permissions, the nine roles, guard scoping |
| [localization.md](localization.md) | Localization | Languages, countries, currencies, timezones, translations, money |
| [settings.md](settings.md) | Settings | Typed values, groups, caching, encryption |
| [audit.md](audit.md) | Audit + Activity | The two trails, immutability, retention |
| [notifications.md](notifications.md) | Notification | Channels, preferences, wiring a provider |
| [media.md](media.md) | Media | S3 disks, collections, conversions, upload validation |

---

## Platform guides

| Document | Covers |
|---|---|
| [error-handling.md](error-handling.md) | Domain exceptions, the JSON envelope, validation |
| [logging.md](logging.md) | Four channels, correlation ids, what is audited automatically |
| [performance.md](performance.md) | Strict mode, eager loading, caching, OPcache |
| [queues.md](queues.md) | Horizon topology, job defaults |
| [search.md](search.md) | OpenSearch engine, Turkish analysis |
| [security.md](security.md) | CSRF, rate limits, passwords, headers, known gaps |
| [testing.md](testing.md) | Suite layout, database strategy, architecture rules |
| [deployment.md](deployment.md) | Image build, migration ordering, rollback |

---

## Conventions in this documentation

- **Cost is stated.** A decision recorded without its trade-off is a preference,
  not a decision.
- **Deferred decisions are listed** rather than left implicit — see the table
  near the end of [001_Architecture.md](001_Architecture.md).
- **Amendments are logged.** When a decision changes, the ADR records it and the
  amendment log at the end of [001_Architecture.md](001_Architecture.md) records
  which sprint changed it. Superseded reasoning stays visible rather than being
  quietly deleted.
- Code in these documents is illustrative. The authority is the documentation,
  and the enforcement is `tests/Architecture/`.
