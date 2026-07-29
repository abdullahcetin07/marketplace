# app/Modules

The seven Foundation modules delivered in Sprint 1.

**Inventory, Order and Payment do not exist yet** — later sprints, each after its
own architecture review. **Organization** and **Store** are frozen v1.0
(ADR-028–036); **Catalog** Phase 1 is complete (ADR-037–041) and deliberately
unfrozen, because **Offer** reaches into it. Offer's architecture review is
approved (ADR-042–046) and it is the module currently being built.

---

## The modules

| Module | Owns | Docs |
|---|---|---|
| **Identity** | Sessions, devices, login history, 2FA, the auth flow | [authentication.md](../../docs/authentication.md) |
| **Localization** | Languages, countries, currencies, timezones, translations | [localization.md](../../docs/localization.md) |
| **Settings** | Business configuration, typed and cached | [settings.md](../../docs/settings.md) |
| **Audit** | Field-level record history, append-only | [audit.md](../../docs/audit.md) |
| **Activity** | User timeline, append-only | [audit.md](../../docs/audit.md) |
| **Media** | Upload validation, optimisation and deletion jobs | [media.md](../../docs/media.md) |
| **Notification** | Channels, preferences, queued delivery | [notifications.md](../../docs/notifications.md) |
| **Organization** | Legal seller company: KYC, members, invitations, documents, bank account, store-opening requests (ADR-028–031) — *frozen v1.0* | [modules/Organization.md](../../docs/modules/Organization.md) |
| **Store** | The storefront: identity, operational state, branding/SEO/contact/settings, localization, seller/admin API, Filament panels, and the composed public read surface `/store/{slug}` — created only by consuming `StoreOpeningApproved`; path-addressed, no custom domains in v1 (ADR-032–036) — *frozen v1.0* | [modules/Store.md](../../docs/modules/Store.md) |
| **Catalog** | The shared product catalog: category tree + per-category attribute schema, brands, products and their variants (SKUs), product media, seller authoring with a moderation lifecycle, and the Core `CatalogQueryContract` (ADR-037–041) — **no price and no stock**, those are Offer/Inventory — *Phase 1 complete* | [modules/Catalog.md](../../docs/modules/Catalog.md) |
| **Offer** | What makes the catalog sellable: a seller org's price + stock for one variant, its lifecycle, the computed buy box, the public "product + its offers" surface and the storefront product-listing contributor (ADR-042–046). **No cart, order, payment or commission** — those are later sprints — *in progress* | [modules/Offer.md](../../docs/modules/Offer.md) |

Media and Notification are **infrastructure only** — the plumbing exists and is
exercised; no product media is attached and no SMS/push provider is bound.

---

## Structure

Each module mirrors the four Core layers:

```
Identity/
  Domain/          models, contracts, DTOs, events, exceptions
  Application/     services, actions, jobs, listeners
  Infrastructure/  repositories, observers, adapters
  Presentation/    controllers, policies, requests, resources
  IdentityServiceProvider.php
```

Related directories: `database/Modules/{Module}/{migrations,Factories,Seeders}`
and `tests/Modules/{Module}/{Unit,Feature}`.

---

## The dependency rules

**Modules never import each other** — with three documented exceptions, each
asserted individually in `tests/Architecture/LayeringTest.php`:

| Exception | Direction | Why |
|---|---|---|
| Anything → **Localization** | read | Platform-wide reference data. Duplicating it per module defeats promoting it to tables. |
| **Settings** → Audit | trait | Settings changes are dispute evidence. Audit reaching into Settings is the worse direction. |
| **Activity** → Identity **events only** | subscribe | The consumer knows the producer's event contract, never the reverse. |
| **Store** → Organization **events only** | subscribe | Store creates the storefront from `StoreOpeningApproved` (ADR-032). References the company by id/uuid, never a model (ADR-033). |
| **Organization** → Store **events only** | subscribe | Organization records `created_store_uuid` from `StoreCreated` (ADR-032 back-reference). |
| **Catalog** → *nothing* | — | Not an exception, the absence of one. Catalog subscribes to no module: it is not created by another context's event, and it holds the proposing company as a bare `proposed_by_org_uuid` (ADR-040). Downstream contexts will read it through the Core `CatalogQueryContract`. |

Everything else goes through domain events. `RecordIdentityActivity` is the
worked example: Identity announces `UserLoggedIn` and stops; Activity
subscribes. Removing the Activity module leaves Identity working with no
listeners rather than fatal.

`App\Models\User` sits **above** the module layer and may import modules —
[001_Architecture.md §6](../../docs/001_Architecture.md).

---

## Adding a module

Step-by-step with a checklist: [docs/modules.md](../../docs/modules.md).

The two things people get wrong:

1. **Register permissions in the provider's `register()`, not a seeder.**
   `PermissionRegistry::resource('store', [UserType::Admin])` — the verb set is
   derived. Then attach them to roles in `RolePermissionSeeder`.
2. **Override `BasePolicy::owns()`** on anything a seller or customer can
   reach. It defaults to `false`, so forgetting denies everything — loud, which
   is the right failure direction.
