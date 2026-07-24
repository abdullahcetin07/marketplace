# app/Shared

Cross-cutting building blocks used by Core and by every module. Deliberately
small — this directory attracts junk if it is allowed to.

**The test for belonging here:** would at least two unrelated modules use it,
and does it carry no business logic of its own?

---

## Enums/

Closed value sets. The single source of truth for each — never mirrored into a
lookup table. Reasoning: [docs/001_Architecture.md §9](../../docs/001_Architecture.md).

| Enum | Notes |
|---|---|
| `Status` | Generic lifecycle. Do **not** overload it with domain-specific meanings |
| `UserType` | The hinge of the auth design: discriminator, guard name, and Spatie `guard_name` in one place |
| `NotificationType` | Delivery channels. An enum, **not** a lookup table (ADR-006) — adding one means writing a driver |
| `MediaType` | Classified from the real MIME type; carries the accepted types and size ceiling |
| `StoreStatus` | State machine, `allowedTransitions()` |
| `OfferStatus` | State machine |
| `ProductStatus` | State machine |

Enum names carry **no `Enum` suffix** (ADR-007).

`StoreStatus`, `OfferStatus` and `ProductStatus` exist as cross-module
contracts. The modules themselves do not.

**`Language`, `Currency` and `Country` are no longer enums.** They became
lookup tables in Sprint 1 and now live in the Localization module as Eloquent
models — an operator must be able to add a language or correct an exchange rate
without a release. See [docs/localization.md](../../docs/localization.md) and
[001_Architecture.md §9](../../docs/001_Architecture.md).

All use `HasEnumHelpers`: `values()`, `options()`, `label()` (translated via
`lang/{locale}/enums.php`), `tryFromValue()` for trust boundaries, `is()`.

---

## Traits/

| Trait | Adds | Requires |
|---|---|---|
| `HasUuid` | Public identifier + route binding | `uuid` unique |
| `HasSlug` | Turkish-aware URL slug, frozen after create | `slug` unique |
| `HasSeo` | JSONB metadata with sensible fallbacks | `seo` jsonb nullable |
| `HasStatus` | `Status` cast, scopes, `markAs()` | `status` indexed |
| `HasCreator` | Stamps the creating actor | `created_by` nullable FK |
| `HasUpdater` | Stamps the last editor | `updated_by` nullable FK |
| `HasMedia` | S3 collections + conversions | `media` table; implement Spatie's interface |

`HasCreator`/`HasUpdater` resolve the actor across all three guards, and leave
null for system writes — attributing a queue worker's write to a person makes
the audit trail lie.

`HasSlug` does **not** regenerate on rename: that silently breaks inbound links
and search rankings. Call `regenerateSlug()` explicitly.

---

## Rules/

`StrongPassword` — 14 chars + symbols for staff, 12 for everyone else, both
checked against Have I Been Pwned. Registered as the framework default.

---

## Support/

`PermissionRegistry` — the source of truth for what permissions exist. Modules
register a resource; the verb set is derived. See
[docs/authorization.md](../../docs/authorization.md).

`helpers.php` — four global functions, kept tiny. A helper earns its place only
when it is needed in Blade, where importing a class is awkward.
`current_actor()` in particular exists because `auth()->user()` resolves only
the default guard and silently returns null for a logged-in seller.
