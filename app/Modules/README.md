# app/Modules

The seven Foundation modules delivered in Sprint 1.

**Payment is COMPLETE** (ADR-060–062, built P1–P5, 2026-08-04): a buyer pays
through PayTR, a verified success callback commits the stock that placement only
held, the commission engine freezes what the platform takes onto the order lines,
the seller's balance is an append-only ledger, an admin records payouts against
it, and a refund reverses all of it — money, commission and stock.
**Organization** and **Store** are frozen v1.0 (ADR-028–036);
**Catalog** Phase 1 is complete (ADR-037–041), **Offer** is complete
(ADR-042–046), **Inventory** is complete (ADR-048–051) and **Order** is complete
(ADR-052–056). None of the last four is frozen: each reaches into the one before
it — Offer into Catalog, Inventory into Offer, Order into all of them, and
Payment into Order and Inventory (it added the reservation port's fourth verb,
`restock`).

**Shipping is being built** (ADR-063–064, spec approved 2026-08-05): **S1 and S2
have landed** — the shipment aggregate, the `cargo_companies` table, the seller's
"kargoya ver" flow, and delivery inference (the buyer's "Teslim aldım" or the
transit sweep) emitting `ShipmentDelivered`. S3–S4 (auto-payout, line-level
partial refund) are not built.

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
| **Inventory** | The availability authority: on-hand + reserved per (seller org, variant), `available = on_hand − reserved` read by the buy box, the append-only movement ledger, and the reserve/release/commit primitives Order calls plus the `restock` Payment added (ADR-048–051). **No cart, order, money or multi-warehouse** — *complete, not frozen* | [modules/Inventory.md](../../docs/modules/Inventory.md) |
| **Order** | The buyer's pipeline: one multi-seller cart, the customer address book, a checkout that splits into one order per seller under a checkout group, immutable price/tax/address snapshots, the KDV breakdown, and the reserve→commit→release calls that make it Inventory's first real caller (ADR-052–056). **Stops at awaiting payment** — no money, no shipping, no commission — *complete, not frozen* | [modules/Order.md](../../docs/modules/Order.md) |
| **Payment** | The money: one payment per checkout group through PayTR's iframe (no card data ever), a hash-verified idempotent callback, the **Inventory commit that keeps ADR-057's promise**, the commission rule engine, the append-only seller ledger, recorded payouts, and refunds that reverse money, commission and stock together (ADR-060–062). **Not the checkout** (Order owns the split and the reservation), not shipping, not invoicing — *complete, not frozen* | [modules/Payment.md](../../docs/modules/Payment.md) |
| **Shipping** | Fulfilment: one shipment per paid order, the seller's "kargoya ver" with a carrier + tracking number, and delivery **inferred** rather than asserted — the buyer confirms or the transit window elapses (ADR-063–064). `ShipmentDelivered` is what releases payout and opens the return window. **No money** — v1 ships free, so it writes no price, KDV or commission — and no carrier API in v1 — *S1–S2 built* | [modules/Shipping.md](../../docs/modules/Shipping.md) |
| **Offer** | What makes the catalog sellable: a seller org's price + stock for one variant, its lifecycle, the computed buy box, the public "product + its offers" surface and the storefront product-listing contributor (ADR-042–046). **No cart, order, payment or commission** — those are later sprints — *complete, not frozen* | [modules/Offer.md](../../docs/modules/Offer.md) |
| **Reviews** | Buyer-written, admin-moderated product feedback: a rating (1–5) + optional text + optional photos, bound to one **delivered** order line and published only after approval (ADR-066–069). The review is the **product's**, tagged with the seller it was bought from; the rating average is computed on read. **No seller rating, no Q&A, no votes, no seller replies** — *complete, not frozen* | [modules/Reviews.md](../../docs/modules/Reviews.md) |

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
| **Reviews** → *nothing* | — | The absence of one, again, and the interesting part is the PHOTOS: they ride `App\Shared\Traits\HasMedia`, not the Media module, so a review can hold images while Media stays on the forbidden list. Catalog and Order are read through Core contracts; the seller tag comes from an order line the buyer never sees. |
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
