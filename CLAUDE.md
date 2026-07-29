# CLAUDE.md

Guidance for AI assistants and new engineers working in this repository.

**Current state: Foundation complete; Identity FROZEN (v2.0); Organization
complete and FROZEN (v1.0, ADR-028–031); Store complete and FROZEN (v1.0,
ADR-032–036).** Custom domains are cut from v1 (ADR-035: path-addressed
`/store/{slug}`) and return only via a future dedicated ADR. The public storefront
is composed (ADR-036): future modules enrich it via the Core
`StorefrontContributorContract` + `StorefrontRegistry`, never by Store depending
on them — **Offer is the first module to actually use that seam** (ADR-046).
**Catalog Phase 1 is complete** (ADR-037–041); **Offer is complete**
(ADR-042–046) and is the newest module. Neither is frozen.

Foundation is a **module group, not a module** (ADR-002). Seven modules exist:
Identity, Localization, Settings, Audit, Activity, Media, Notification.
**No `app/Modules/Foundation/` directory exists or should be created.**

**Identity is frozen** — only bug/security/compatibility fixes or changes a later
module explicitly requires (see the freeze notice in
[docs/modules/Organization.md](docs/modules/Organization.md)'s sibling,
`docs/modules/Identity.md`).

**Organization is complete and FROZEN** (v1.0, Phases 1–8; ADR-028–031). Only
bug/security/compatibility fixes or changes a later module requires. Two
documented follow-ups: `OrganizationSettings` and the Activity user-timeline
listener (see the freeze notice in
[docs/modules/Organization.md](docs/modules/Organization.md)).

**Store is complete and FROZEN** (v1.0, ADR-032–036). It consumes
`StoreOpeningApproved` to create the storefront (ADR-028/032), references its
owning company by id/uuid only — never importing an Organization model (ADR-033)
— and exposes a separate public read surface (ADR-034), path-addressed and
composed. **Store is NOT products, catalogue, offers, inventory, orders or
payments.**

**Catalog Phase 1 is complete** ([docs/modules/Catalog.md](docs/modules/Catalog.md),
ADR-037–041) — the **catalog structure only**: the category tree + per-category
attribute schema (Category Manager, ADR-038), brands, the Product/ProductVariant
aggregate with variants first-class (ADR-039), product media, seller authoring
("ürün aç") under a moderation lifecycle, search indexing, and the Core
`CatalogQueryContract`. The catalog is **shared** — one product, many sellers —
and a seller never gets a copy (ADR-037).

**It is deliberately NOT frozen**: Offer reaches into it. See Catalog.md §15 for
what shipped, what is deliberately absent, and the two open follow-ups (a
legal-name label on the seller's org picker; the Activity user timeline, shared
with Organization's).

**Offer is COMPLETE** (2026-07-29; ADR-042–046,
[docs/modules/Offer.md](docs/modules/Offer.md)) — the newest module. An Offer is a
seller org's price + stock for one variant (one product, many offers, ADR-042);
stock lives on the offer this sprint (ADR-043); offers are not moderated — live on
save, admin reactive suspend (ADR-044); the buy box is computed, never stored
(ADR-045); Offer ships the storefront product-listing contributor Catalog deferred
(ADR-046). Cart/order/payment/commission stay out of scope.

**It is NOT frozen either**: Inventory is next and becomes the stock authority
(ADR-043), which means reaching into it. See Offer.md §15 for what shipped, three
recorded deviations, the read-only additions it required of Catalog/Store/
Organization, and five open follow-ups.

**Offer imports NO module** — the strictest boundary on the platform. It reads
Catalog, Organization and Store through Core contracts only, and subscribes to
their events BY CLASS-STRING. `LayeringTest` fails the build on any import;
`CatalogBoundaryTest` asserts the reverse — that no price or stock has leaked into
the Catalog.

**A Product has no price and no stock.** That is the module's defining boundary:
price/stock/condition are an **Offer**, on-hand quantity is **Inventory**. It
also registers no storefront contributor in Phase 1 (ADR-041) and imports neither
Organization nor Store — the proposing company is a bare `proposed_by_org_uuid`
(ADR-040).

**Do not create Inventory, Order or Payment modules.** Those are later sprints,
and only after their architecture review is approved.

---

## The rule that outranks everything else

> **Sprint prompts never override documentation.** When a sprint brief conflicts
> with the chain below, STOP, report, and get an explicit amendment before
> writing code. Never pick a side silently. (ADR-018)

**Document precedence (ADR-003):**

1. `CLAUDE.md`
2. [`docs/Architecture_Decision_Record.md`](docs/Architecture_Decision_Record.md)
3. [`docs/001_Architecture.md`](docs/001_Architecture.md)
4. [`docs/003_Database_Standards.md`](docs/003_Database_Standards.md)
5. [`docs/002_Coding_Standards.md`](docs/002_Coding_Standards.md)
6. [`docs/004_Naming_Conventions.md`](docs/004_Naming_Conventions.md)
7. [`docs/005_API_Standards.md`](docs/005_API_Standards.md)
8. Module specifications

Every approved decision lives in the **ADR**. It outranks documents 3–8 until
they are updated to match it. Amending a decision means updating the ADR *and*
the amendment log at the end of `001_Architecture.md` in the same change.

Documentation is **executable architecture** — implementation must never
contradict it.

---

## Commands

Everything runs in Docker. Never assume PHP or Composer on the host.

```bash
make install     # first-time setup
make check       # lint + analyse + test — exactly what CI runs
make shell       # shell into the app container
make help        # all targets
```

Before proposing any change as done, `make check` must pass.

---

## Non-negotiables

Enforced by tests, not by convention. Breaking one fails the build.

1. **`declare(strict_types=1)` in every PHP file.**
2. **Modules never import each other** — except Localization, which is
   platform-wide reference data. Everything else goes through domain events.
   `tests/Architecture/LayeringTest.php`.
3. **`app/Core/Domain` never imports Eloquent, `Request` or the `DB` facade.**
10. **No `cache()`, `request()`, `encrypt()` or `decrypt()` in any Domain
    layer** (ADR-019). `now()` and `config()` are fine. Caching and encryption
    belong to Infrastructure; request access belongs to Presentation. The rule
    covers helper functions, not just Facade classes.
11. **DTOs use the `DTO` suffix** and live in `{Module}/Domain/DTOs/`
    (ADR-021). Never `...Data`, never `Domain/Data/`.
4. **Roles are referenced by name, never by id.** Use
   `config('marketplace.roles.*')`.
5. **Policies check permissions, never roles** — except the one documented
   privilege-escalation guard in `UserPolicy`.
6. **Money is an integer of minor units.** Never a float. Use the `Currency`
   *model*. `DECIMAL` is only for rates and percentages — exchange rates, tax
   rates, commission and discount percentages (ADR-005). APIs format money as
   decimal strings.
7. **Public identifiers are UUIDs.** Internal `id` never leaves the application.
8. **No `dd()`, `dump()`, `var_dump()`, `die()` or `exit()`** anywhere.
9. **Audit and activity entries are append-only.** The models refuse updates and
   deletes. Do not add an escape hatch.

---

## Enum or lookup table?

The test is **who owns the value**:

- Adding a case requires writing code to handle it → **enum**
  (`UserType`, `Status`, `NotificationType`, `MediaType`, `ActivityType`, …)
- An operator must enable/disable/reconfigure it without a release → **table**
  (`countries`, `currencies`, `languages`, `timezones`, `tax_rates`,
  `payment_methods`, `shipping_methods`)

**Notification channels are an enum, not a lookup table** (ADR-006).

Enum class names carry **no `Enum` suffix** (ADR-007) — `OrderStatus`, never
`OrderStatusEnum`.

Lookup tables use `is_active`; business entities use `status` (ADR-015).

Full reasoning and its cost: [docs/001_Architecture.md §9](docs/001_Architecture.md).

---

## Where code goes

| It is... | It goes in |
|---|---|
| A business rule | `app/Modules/{Module}/` |
| A base class modules extend | `app/Core/{Domain,Application,Infrastructure,Presentation}/` |
| An enum, trait or helper two modules share | `app/Shared/` |
| Authentication identity (`User` and subclasses) | `app/Models/` |

Dependency tiers: `app/Models` → `app/Modules` → `app/Core` → `app/Shared`.
`User` may import modules; modules may not import each other.
[docs/001_Architecture.md §6](docs/001_Architecture.md).

---

## Action or service?

| | Transaction | Methods | Named |
|---|---|---|---|
| Action | owns one | `handle()` | verb + noun — `LoginAction` |
| Service | none of its own | several | aggregate — `AuthService` |

If you cannot name it with one verb and one noun, it is a service that calls
several actions.

**Size limits.** 300 lines per class is a *review threshold*, not a hard rule
(ADR-020) — exceed it with documented justification. Still strict: **50 lines
per method, 7 constructor dependencies**, and high cyclomatic complexity must
be refactored.

Side effects (mail, webhooks, indexing) go in `BaseAction::after()`, which runs
**after commit**.

---

## Things that will surprise you

- **Strict mode is on.** Lazy loading *throws* in development. Declare eager
  loads on the repository's `$with`, not at the call site.
- **`BaseRequest::authorize()` defaults to `false`.** Override it deliberately.
- **`BasePolicy::owns()` defaults to `false`.** Any seller- or customer-facing
  policy must override it or it denies everything.
- **`BaseException::$reportable` defaults to `false`.** Expected domain failures
  are not incidents.
- **`auth()->user()` is a bug here.** It resolves only the default guard and
  returns null for a logged-in seller. Use `current_actor()` or name the guard.
- **Unit tests have no database.** If yours needs one, it is a Feature test.
- **Subclasses of `BaseJob` must call `parent::__construct()`.**
- **`Language::default()` and `Currency::default()` throw if unseeded.** Feature
  tests that touch locale must seed Localization first — `$this->seedPlatform()`.
- **Settings never break boot.** `settings()` returns the caller's default when
  the table is unreachable, by design.

---

## Authentication model

One `users` table, three guards, three subclasses scoped by `users.type`.
`UserType` is simultaneously the discriminator, the guard name and the Spatie
`guard_name` — do not introduce a mapping between them.

`tests/Feature/Auth/GuardIsolationTest.php` proves the guards cannot resolve
each other's users. **A failure there is a privilege-escalation bug.** Never
adjust that file to make it pass.

The flow itself lives in `app/Modules/Identity/Application/` — `AuthService`
plus one action per use case. Controllers never authenticate directly.

---

## Adding permissions

Register a resource in the module's service provider; never hand-write names.

```php
PermissionRegistry::resource('store', [UserType::Admin, UserType::Seller]);
PermissionRegistry::ability('store.approve', [UserType::Admin]);
```

Then `make permissions`. Attach them to roles in `RolePermissionSeeder`.

Nine roles (ADR-013): Super Admin, Admin, Editor, Category Manager, Support,
Finance, Seller, Seller Employee, Customer. **Super Admin and Admin are
distinct** — Super Admin bypasses every policy, Admin holds an enumerated set.
Category Manager remains part of the system.

---

## Documentation expectations

- **Every structural directory has a `README.md`** — `app/`, `app/Core/*`,
  `app/Shared/`, `app/Modules/`, `config/`, `database/`, `docker/`, `tests/`.
  Update it when its contents change.
- **Individual modules are documented in `docs/{module}.md`**, not in a README
  inside the module. One place per module, with the reasoning and its cost;
  `app/Modules/README.md` is the index. A stub README beside a real document
  is a second place to go stale.
- `docs/` explains **why**, and states what each decision **costs**. A decision
  recorded without its trade-off is a preference.
- Amending an architecture decision means updating
  `docs/Architecture_Decision_Record.md` **and** the amendment log in
  `docs/001_Architecture.md` in the same change.

---

## Environment note

Local PHP on this machine is 8.1 and the project requires 8.4, and Composer here
cannot reach Packagist (no `openssl`). Run everything through Docker. Do not try
to `composer install` on the host. `php -l` on the host reports false parse
errors for PHP 8.3+ typed class constants — that is expected noise.

---

## Reading order for a newcomer

1. [README.md](README.md)
2. [docs/Architecture_Decision_Record.md](docs/Architecture_Decision_Record.md) — every approved decision
3. [docs/001_Architecture.md](docs/001_Architecture.md) — including the amendment log
4. [docs/authentication.md](docs/authentication.md) and
   [docs/authorization.md](docs/authorization.md)
5. `app/Core/README.md`, then `app/Modules/README.md`
6. [docs/modules.md](docs/modules.md) before writing a module
