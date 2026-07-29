# Store Module Specification
Version: 1.0 — **COMPLETE & FROZEN (2026-07-24). ADR-032–036 ratified.**

> ## 🧊 FREEZE NOTICE — Store v1.0 (2026-07-24)
>
> The Store module is **feature-complete and frozen**. Phases 1–7 are done:
> event-driven creation, operational state, cross-context authorization,
> storefront customization, the composed public storefront, seller & admin API,
> Filament panels, and hardening. Only **bug/security/compatibility fixes or
> changes a later module explicitly requires** are permitted — no new features.
>
> **Documented follow-ups (deferred, not gaps):** custom domains (a future
> dedicated ADR re-adds `StoreDomain` + the Owner-only capability additively,
> ADR-035); `StoreLanguage` multi-language publishing; the `BelongsToStore` trait
> and a generalised `StoreContext` resolver (§20.5/20.6) — introduced with their
> first real consumer (Product). The composition seam (ADR-036) is the standing
> extension point: Product/Category/Campaign/Review/Statistics enrich the
> storefront via `StorefrontContributorContract`, never by Store depending on them.
>
> **Owner-approved refinements after freeze (2026-07-29):**
> - **Store name is unique platform-wide** — no two stores may share a name.
>   Validated at request time through a new `StoreQueryContract` read
>   (`storeNameExists`) and enforced by a DB unique index for integrity.
> - Sellers request an **additional** store from the seller **"Mağazalarım"** page
>   ("Yeni Mağaza Talep Et"), the relocated Store Opening Request entry point (the
>   standalone SOR nav item is removed; onboarding's first store comes with the org,
>   see [Organization.md](Organization.md)). The creation path is unchanged — a store
>   is still created only by `StoreOpeningApproved` (ADR-028/032).
>
> **As built (2026-07-29):** the "Yeni Mağaza Talep Et" header action is a LINK, not
> a form. The request belongs to Organization and Store may not import it (ADR-033),
> so the button points at the Organization-owned page by ROUTE NAME — the same
> name-not-an-import coupling Offer uses to subscribe to Catalog's events. The cost
> is that a rename on the Organization side breaks the link at runtime rather than at
> build time; `Route::has()` hides the button instead of 500ing, and a feature test
> asserts the route exists. `storeNameExists()` compares on `LOWER(name)`, the same
> expression the unique index is built on.

Sprint: **Store-0 (specification)**. This document is the architecture-review
artifact `CLAUDE.md` requires *before* any Store code, migration, model,
controller or Filament resource. Nothing in `app/Modules/Store/` exists or may
be created until this document is approved and the three ADRs below (032–034)
are ratified.

Governed by: `CLAUDE.md` → `Architecture_Decision_Record.md` →
`001_Architecture.md` → `003_Database_Standards.md` → `002_Coding_Standards.md`
→ `004_Naming_Conventions.md` → `005_API_Standards.md` → this document
(ADR-003).

> ## ✅ ADR-conflict report — RESOLVED
>
> **No conflict with an existing ADR was found.** Store honours ADR-028 (created
> only via an approved request), ADR-030 (isolated by `organization_id`), ADR-027
> (auditable aggregates), ADR-025 (out-of-band credentials), ADR-023 (declarative
> casts). The three new decisions were **ratified 2026-07-23**:
>
> - **ADR-032 — Event-driven, idempotent store creation** (§0.2) ✅
> - **ADR-033 — Cross-context references by id/UUID, never by code** (§0.3) ✅
> - **ADR-034 — The public storefront is a distinct, unauthenticated read
>   surface** (§0.4) ✅
>
> ## ✅ Rulings (2026-07-23)
>
> 1. **ADR-032/033/034 ratified** — ADR record + `001_Architecture.md` amendment
>    log updated.
> 2. **Locale (§4.3): platform defaults.** A Store is created with the platform
>    Localization defaults; the seller later configures language/currency/
>    timezone. **Frozen Organization is not modified.**
> 3. **Back-reference (§4.2): approved.** Organization consumes `StoreCreated` and
>    fills `StoreOpeningRequest.created_store_uuid`. Store remains the
>    authoritative owner of the link via `stores.opening_request_uuid`.
> 4. **§9.1 cross-context authorization: option (a).** A small **Core**
>    `OrganizationAuthorizationContract`; Organization provides the
>    implementation and remains the single source of truth for memberships and
>    capabilities. Store depends **only** on the contract. **No replicated read
>    model, no event-sync.** This is the standard mechanism for every future
>    seller-owned module (Product, Catalog, Offer, Order, Payment). See §20.
> 5. **Layering.** `LayeringTest` gains a precise Store rule and mutual
>    `Domain\Events` exceptions between Store and Organization (each consumes the
>    other's events — the Audit/Activity precedent).

---

> ## 🔻 SCOPE AMENDMENT — ADR-035 (2026-07-23): no custom domains in v1
>
> **Stores are addressed only by platform path — `/store/{slug}` (localised
> `/magaza/{slug}`), resolved by slug.** v1 has **no `StoreDomain` aggregate, no
> subdomains, no custom domains, no DNS/TXT verification, no host resolution**.
>
> Wherever this document still describes `StoreDomain`, `StoreDomainType/Status`,
> `subdomainHost()`, `verification_token`, host-based resolution, a domains API,
> domain notifications, `StoreDomainPolicy`, `StoreDomainTest`, or a
> `store.manage_domains` / `canManageOrganizationDomains` capability — **that
> content is superseded by ADR-035 and deferred.** Custom domains return only via
> a future dedicated ADR that adds them additively. The model stays extensible:
> the slug is the sole public identifier, `isLive()` is the single place a
> "serving" precondition would tighten, and the removed capability is re-added by
> that future ADR. Sections updated in place: §5, §7, §8, §17, §20.6.

# 0. Scope and the three new decisions

## 0.1 What a Store is

A **Store is the storefront** — the branded, addressable selling surface a
customer visits. It is an **independent bounded context**, not a child CRUD of
Organization. Organization is the *company* (who is legally selling); Store is
the *shop* (where and how they present it). One Organization owns many Stores
(ADR-028); each Store is one storefront with its own identity, look, address and
operational state.

Store **owns**: storefront identity (name, handle, store number), settings,
branding, SEO, **domains** (subdomain + verified custom domains), contact
information, storefront **localization**, and **operational state** (draft →
active → paused/closed/suspended).

Store **is created only by consuming `StoreOpeningApproved`** (ADR-028). A seller
never creates a Store directly, and Store never creates itself from a seller
action — only from an approved request.

```
Organization  ──StoreOpeningApproved──▶  Store (this context)
   (company)         (ADR-028 event)        (storefront)
                                              ├── StoreSettings
                                              ├── StoreBranding   (Media)
                                              ├── StoreSeo
                                              ├── StoreDomain[]   (subdomain + custom, DNS-verified)
                                              ├── StoreContact
                                              └── StoreLocalization
```

Store **is NOT**: products, catalogue, offers, inventory, orders, shipping,
payments, campaigns (all later, separate contexts). Store is the shell those
hang off; it owns none of their data.

## 0.2 ADR-032 — Event-driven, idempotent store creation

**Proposed.** The Store is created **only** by a listener on
`StoreOpeningApproved` (ADR-028). The listener is **idempotent**, keyed on the
request UUID: at-least-once delivery, a replay, or a double-dispatch creates
**one** Store, never two. On success it emits `StoreCreated` carrying the new
store's UUID.

Rationale: ADR-028 makes approval the sole creation trigger; this states the
consumer contract. Idempotency is not optional — event buses redeliver, and a
duplicated storefront is a customer-facing defect and a limit-accounting bug.

Cost: creation is asynchronous-tolerant and must be written defensively (unique
constraint on `opening_request_uuid`, upsert-or-skip). A creation failure must
be observable (it leaves the request approved but storeless) and retryable.

## 0.3 ADR-033 — Cross-context references by id/UUID, never by code

**Proposed.** A downstream context references an upstream aggregate by its
**id (+ UUID)**, with an optional database FK for integrity, but **never imports
the upstream module's models, services or repositories**. Store carries
`organization_id` (ADR-030) and `organization_uuid`; it has **no
`belongsTo(Organization)` relation**. Data it needs from upstream arrives in the
**event payload** (denormalized at the moment of the fact) — never a live code
call across the boundary.

Rationale: this generalises the `created_store_uuid` pattern ADR-028 already
uses in the other direction. It is what lets the two contexts deploy, test and
reason independently while `LayeringTest` still forbids the code coupling.

Cost: some upstream data is denormalised into events or the Store row (e.g. the
org's display name at creation) and can go stale; where freshness matters, the
upstream must publish an update event the downstream subscribes to. No lazy
`$store->organization->name` convenience — that coupling is deliberately absent.

## 0.4 ADR-034 — The public storefront is a distinct, unauthenticated read surface

**Proposed.** Store data splits in two:

- **Public** — name, branding, SEO, verified domains, public contact, locale.
  Served **without authentication**, resolved by **domain or slug**, to render
  the storefront. Its own throttle; never exposes internal ids or private fields.
- **Private** — settings, operational internals, domain-verification tokens,
  draft state. Behind the seller guard + org capabilities (ADR-030) and the
  admin guard.

Rationale: every platform API to date sits behind `auth:sanctum`. A storefront
is meant to be seen by anonymous shoppers, so Store introduces the platform's
first **public read boundary**. Keeping it a separate, explicitly-scoped surface
(distinct controllers, resources and middleware) stops private fields leaking
into a page anyone can load.

Cost: two resource shapes per concept (public vs private), a domain-resolution
middleware, and a discipline that the public resource is allow-list only.

---

# 1. Purpose

## 1.1 Responsibilities

Store owns:

- **Storefront identity** — display name, globally-unique `slug`/handle, a public
  `store_number`, and the operational `status`.
- **Settings** — storefront operational preferences (display currency, order-note
  policy placeholders, announcement bar, etc.).
- **Branding** — logo, banner, favicon (Media, public disk), theme colours.
- **SEO** — meta title/description/keywords, canonical, robots, OG image.
- **Domains** — the platform subdomain (`{slug}.{platform}`) and any number of
  **custom domains**, each DNS-verified before it can serve.
- **Contact information** — public email/phone/address and support hours.
- **Localization** — the storefront's default language, currency and timezone,
  and the set of languages it publishes in (referencing Localization tables).
- **Operational state** — the lifecycle (§7): draft, active, paused (vacation),
  closed, suspended, archived.

## 1.2 Non-responsibilities

- **Creating itself.** Only `StoreOpeningApproved` creates a Store (ADR-028/032).
- **Products, catalogue, offers, inventory, orders, shipping, payments,
  campaigns.** Later contexts; Store owns none of their tables.
- **The organization / company.** Organization owns legal identity, KYC,
  members, the bank account, the store *allowance*. Store references the org by
  id (ADR-033) and never edits it.
- **The store limit.** Organization owns and enforces the allowance (ADR-028);
  Store never checks or changes it — an approved request already cleared it.
- **Authentication, media storage mechanics, notification delivery** — Identity /
  `HasMedia` / Notification, consumed as platform infrastructure.

## 1.3 Module boundaries

Enforced by `tests/Architecture/LayeringTest.php`. Store may import:

- `app/Core`, `app/Shared` (bases, `HasMedia`, `HasUuid`, `HasStatus`, enums,
  `PermissionRegistry`, the Core notification/invitation infra).
- `App\Modules\Localization\Domain` — languages/currencies/timezones (the one
  standing cross-module dependency).
- `App\Models\User` — a seller acting on their store is a user (as Organization
  and Identity do).
- `App\Modules\Organization\Domain\Events` — **only** the events Store consumes
  (`StoreOpeningApproved`, and org-lifecycle events it reacts to). Never
  Organization's models/services/repositories (ADR-033).

Store may **not** import Organization internals, Identity, Settings, Audit,
Activity, Media, Notification internals, or any future selling module. Everything
crosses through **events** (§8).

## 1.4 Relationship with Organization

- **Upstream, one-way, event-driven.** Store is created by `StoreOpeningApproved`
  and carries `organization_id`/`organization_uuid` (ADR-033). It reacts to
  `OrganizationSuspended` (suspend its stores) and `OrganizationApproved`/etc.
  where operationally relevant.
- **Store reports back** with `StoreCreated`; Organization may consume it to fill
  `StoreOpeningRequest.created_store_uuid` (§0, open item 3).
- **No FK-navigation in code.** `stores.organization_id` may carry a DB FK for
  integrity, but the model exposes no `organization()` relation.

## 1.5 Relationship with Foundation

- **Localization** — locale FKs (imported, permitted).
- **Media** — branding assets via `App\Shared\Traits\HasMedia` (public disk).
- **Audit** — Store aggregates are `Auditable`; admin actions carry a reason
  (ADR-027).
- **Activity / Notification** — via events + the Core notification base.
- **Identity** — a seller is a `User`; Store never authenticates.

## 1.6 Relationship with later contexts (Product, Order, …)

Those contexts reference a Store by `store_id`/UUID (ADR-033), exactly as Store
references Organization. Store publishes `StoreCreated`/`StoreActivated`/
`StoreClosed`/`StoreSuspended` so they can react (e.g. hide a suspended store's
listings). Store imports none of them.

---

# 2. Domain Model

Public identifiers are UUIDs (ADR §8). Money is not stored here. Aggregates are
`Auditable` unless noted.

## 2.1 `Store` (aggregate root)

| Column | Type | Notes |
|---|---|---|
| `id` / `uuid` | | |
| `organization_id` | FK → organizations.id (integrity only; no code relation, ADR-033) | |
| `organization_uuid` | uuid | denormalised for the public API / cross-context reads |
| `opening_request_uuid` | uuid, **unique** | the approved request this store came from — the idempotency key (ADR-032) |
| `name` | string | display name |
| `slug` | string, **unique** | global handle → subdomain |
| `store_number` | string, unique | short public code (e.g. `ST-000123`) |
| `status` | `StoreStatus` | draft / active / paused / closed / suspended / archived |
| `default_language_id` | FK → languages.id | |
| `default_currency_id` | FK → currencies.id | |
| `timezone_id` | FK → timezones.id | |
| `activated_at` / `paused_at` / `closed_at` / `suspended_at` | tz, nullable | |
| `suspended_by` / `suspension_reason` | | admin action |
| timestamps + `deleted_at` | | soft-deletes |

Relations: `settings()` (HasOne), `branding()` (HasOne), `seo()` (HasOne),
`domains()` (HasMany), `contact()` (HasOne), `languages()` (the published set),
plus locale `belongsTo`s. **No `organization()` relation** (ADR-033).

Derived: `isLive(): bool` (status Active and has a verified serving domain),
`primaryDomain(): ?StoreDomain`, `subdomainHost(): string`.

## 2.2 `StoreSettings` (HasOne)

Operational preferences: `display_currency_id`, `announcement`,
`order_note_enabled`, `weight_unit`, `dimension_unit`, `metadata` (jsonb for
forward-compatible flags). Owned by the store, not the platform Settings module.

## 2.3 `StoreBranding` (HasOne, HasMedia)

Logo, banner and favicon in the **public** media collections; plus theme fields:
`primary_color`, `accent_color`, `theme` (a named preset). Assets are public
(a shopper must load them), unlike Organization's private documents.

## 2.4 `StoreSeo` (HasOne)

`meta_title`, `meta_description`, `meta_keywords` (jsonb), `canonical_url`,
`robots` (`index,follow` default), `og_image` (Media). Sensible defaults derived
from the store name when unset.

## 2.5 `StoreDomain` (HasMany)

| Column | Type | Notes |
|---|---|---|
| `id` / `uuid` | | |
| `store_id` | FK | |
| `host` | string, **unique** | the domain (custom) or `{slug}.{platform}` (subdomain) |
| `type` | `StoreDomainType` | subdomain / custom |
| `status` | `StoreDomainStatus` | pending / verifying / verified / failed |
| `is_primary` | boolean | one primary serving domain per store |
| `verification_token` | string | the DNS TXT value the seller must publish; not a bearer credential, but shown only to the store's own members |
| `verified_at` | tz, nullable | |

The subdomain is auto-created, always `verified`. A custom domain starts
`pending`; the seller publishes a DNS TXT record; a verification check confirms
it and flips it to `verified`, after which it may serve and be made primary.

## 2.6 `StoreContact` (HasOne)

`public_email`, `public_phone`, `address` (structured or jsonb), `support_hours`
(jsonb). Public-facing; distinct from the org's private/legal contact.

## 2.7 `StoreLanguage` (pivot / HasMany)

The languages the storefront publishes in, referencing `languages`. One is the
default (mirrors `stores.default_language_id`).

## 2.8 Enums (module-owned, no `Enum` suffix — ADR-007)

- `StoreStatus` — Draft, Active, Paused, Closed, Suspended, Archived
- `StoreDomainType` — Subdomain, Custom
- `StoreDomainStatus` — Pending, Verifying, Verified, Failed

An enum that crosses a boundary on an event is promoted to `App\Shared\Enums`
(the `LoginThreatKind` precedent).

---

# 3. Business Rules

## 3.1 Store lifecycle (operational state)

```
   StoreOpeningApproved
          │
        Draft ──activate──▶ Active ──pause──▶ Paused ──resume──▶ Active
          │                   │  │                                  ▲
        (setup)           suspend│└────────close────────▶ Closed ──┘ (reopen)
                              │  │                            │
                              ▼  ▼                          archive
                          Suspended (admin) ──reinstate──▶ Active
```

- **Draft** — created from an approved request; not publicly reachable; the seller
  completes branding/locale, then **activates**.
- **Active** — live and serving at `/store/{slug}` (ADR-035).
- **Paused** — vacation mode: temporarily not selling, page shows a notice.
  Seller-controlled, self-reversible.
- **Closed** — the seller closed the store; reversible by reopening.
- **Suspended** — an admin froze it (policy breach); only an admin reinstates.
- **Archived** — retired; read-only end-state, distinct from soft-delete.

Activation requires a valid from-state and a set default locale (which creation
always provides). *(No verified-domain precondition in v1 — ADR-035.)*

## 3.2 Creation invariants (ADR-028/032)

- Created **only** by the `StoreOpeningApproved` listener.
- **Idempotent** on `opening_request_uuid` (unique) — one store per request.
- Slug/store_number/subdomain are generated **globally unique** at creation; the
  requested slug is honoured when free, otherwise suffixed, and the seller may
  request a change later (which re-checks uniqueness).
- Emits `StoreCreated`.

## 3.3 Soft-delete & restore

`deleted_at` is a recoverable removal, orthogonal to `status`. Hard delete is a
retention-job concern only.

## 3.4 Domain verification

A custom domain cannot serve until `verified`. Verification is a DNS TXT check of
`verification_token`; failure is retryable; the subdomain never needs it.

## 3.5 Slug/handle & domain uniqueness

`slug`, `store_number` and every `host` are globally unique. A slug change
re-points the subdomain and must not collide.

## 3.6 Organization coupling to store state

On `OrganizationSuspended`, Store **suspends** the org's stores (they cannot
serve while the company is frozen); on the org's reinstatement they return to
their prior state. This is an event reaction, not a code call (ADR-033).

---

# 4. Store Creation (the `StoreOpeningApproved` consumer)

## 4.1 The listener (ADR-032)

`CreateStoreFromApprovedRequest` subscribes to `StoreOpeningApproved`. It:

1. Looks up any existing store with the event's `requestUuid`; if present,
   **returns** (idempotent).
2. Creates the `Store` (Draft) with `organization_id`/`organization_uuid`, the
   `storeName`, a unique slug derived from the requested `slug`, a `store_number`,
   the platform default locale (§4.3), and the auto subdomain (`verified`).
3. Seeds empty `StoreSettings`/`StoreBranding`/`StoreSeo`/`StoreContact`.
4. Emits `StoreCreated(storeUuid, organizationId, openingRequestUuid, …)`.

Not queued vs queued: creation is a real transaction and should be synchronous
within the event dispatch, but must tolerate redelivery (idempotency) either way.

## 4.2 Reporting back

Organization may consume `StoreCreated` to set `created_store_uuid` on the
request (§0, item 3). Store’s own `opening_request_uuid` is the authoritative
link regardless.

## 4.3 Locale at creation — RULED

The event carries no locale. **Ruling:** create with the platform Localization
defaults (`Language::default()`, `Currency::default()`, the platform default
timezone). The seller later configures the storefront's language, currency and
timezone (§6). **Frozen Organization is not modified** and
`StoreOpeningApproved` is not extended.

---

# 5. Addressing & SEO (ADR-035)

- **Addressing**: a store is reached at the platform path `/store/{slug}`
  (localised `/magaza/{slug}`), resolved by the globally-unique `slug`. **No
  per-store domain, subdomain, or DNS in v1.** The slug is the sole public
  identifier; changing it changes the public URL (an Owner-level concern when
  slug editing lands).
- **Public resolution** (ADR-034): the public read surface resolves `{slug}` →
  Store by a simple indexed lookup — no host middleware.
- **SEO**: per-store meta; robots defaults to `index,follow` for Active stores and
  `noindex` for non-live states so a paused/draft store is not indexed.
- **Custom domains — deferred (ADR-035).** A future ADR adds a `StoreDomain`
  aggregate (custom host + DNS-TXT verification + host resolution) and an
  Owner-only `StoreManageDomains` capability, additively. Nothing in v1 blocks it:
  the public surface would gain a host→slug resolution step ahead of the existing
  slug lookup.

---

# 6. Branding & Localization

- **Branding**: logo/banner/favicon on the **public** media disk; theme colours +
  a named theme preset. (Organization's documents were private; storefront assets
  are public by nature.)
- **Localization**: `default_language/currency/timezone` reference Localization
  tables; `StoreLanguage` lists the published languages. Defaults resolve to the
  platform defaults at creation (§4.3).

---

# 7. Operational state transitions

| Action | From | To | Who |
|---|---|---|---|
| activate | Draft, Closed | Active | seller (`store.manage`) — requires a set locale |
| pause | Active | Paused | seller |
| resume | Paused | Active | seller |
| close | Active, Paused | Closed | seller |
| suspend | any live | Suspended | admin (`store.suspend`) — audited, reason |
| reinstate | Suspended | prior | admin (`store.reinstate`) |
| archive | Closed, Suspended | Archived | admin/seller |

Every admin transition is audited with a reason (ADR-027). Non-live states set
`robots=noindex` (§5).

---

# 8. Events

Extend `App\Core\Domain\Events\BaseEvent`.

**Consumed** (Organization's `Domain\Events`, ADR-033):
- `StoreOpeningApproved` → create the store (§4).
- `OrganizationSuspended` → suspend its stores; (reinstate mirror).

**Produced** (Store's own):
- `StoreCreated` — Organization (back-reference), later contexts, Activity/Audit.
- `StoreActivated`, `StorePaused`, `StoreClosed`, `StoreSuspended`,
  `StoreReinstated`, `StoreArchived`. *(All built in Phase 2.)*
- *(Domain-verification events deferred with custom domains — ADR-035.)*

Consumers (Audit/Activity/later modules) import Store's `Domain\Events` only —
the reverse direction is forbidden.

---

# 9. Policies

- `StorePolicy` — seller capabilities (view/update/activate/pause/close via
  Organization membership capabilities on the store's org, ADR-030) + admin
  abilities (`store.view_any`, `store.suspend`, `store.reinstate`).
- `StoreDomainPolicy` — manage domains (capability), verify.
- `StoreBrandingPolicy` / `StoreSeoPolicy` / `StoreSettingsPolicy` — the
  storefront-management capability.
- **Public read** needs no policy — it is unauthenticated allow-list output
  (ADR-034), gated only by store status (only live stores render).

**Isolation (ADR-030):** every seller-facing store policy resolves the actor's
membership of the store's `organization_id` — a member of another org sees
nothing. **Ruling (§9.1, option a):** this read goes through the Core
`OrganizationAuthorizationContract` (§20.1); Store never imports Organization
(ADR-033).

## 9.1 Roles & capabilities — RULED (option a)

Store management is authorized through the **Core
`OrganizationAuthorizationContract`** (§20.1). Organization remains the single
source of truth for memberships and capabilities; Store depends only on the
contract. There is **no replicated read model** in Store.

The contract answers `hasCapability(userId, organizationId, capability: string)`.
Store passes capability strings (`store.manage`, `store.manage_domains`). For
Organization to resolve those, its `OrganizationCapability` enum + role matrix
gain the two store-management capabilities — a **permitted, Store-required change
to the frozen Organization module** (§20.2), surfaced for approval, not made
silently. Because Store's authorization is needed only from Phase 2 (seller
actions), the contract and the Organization capability additions land in **Phase
2**, not Phase 1 (creation is a system event reaction with no user authz).

---

# 10. DTOs

`DTO` suffix, `Domain/DTOs` (ADR-021): `UpdateStoreDTO`, `UpdateStoreSettingsDTO`,
`UpdateStoreBrandingDTO`, `UpdateStoreSeoDTO`, `UpdateStoreContactDTO`,
`AddStoreDomainDTO`, `UpdateStoreLocalizationDTO`. Creation takes no DTO — it is
driven by the event payload.

---

# 11. Repositories

Contracts in Domain, implementations in Infrastructure, `$with` declared:
`StoreRepositoryContract` (by uuid / slug / opening_request_uuid / organization_id;
`findPublishedBySlug` for the public surface — Active only, profile eager-loaded;
admin pagination). *(No `StoreDomainRepository` — domains deferred, ADR-035.)*

---

# 12. API

Envelope per ADR-009. Three surfaces:

## 12.1 Public storefront (ADR-034/035/036 — no auth, own throttle) ✅ DONE
| Method | Path | Notes |
|---|---|---|
| GET | `/store/{slug}` | public store payload, resolved by slug |
| GET | `/magaza/{slug}` | localised segment — same handler, config-driven |

One route per configured `marketplace.store.public_path_segments`; `throttle:storefront`
(IP-keyed, generous). Only **Active** stores render; a missing OR non-live store
returns the same 404 (`store_unavailable`) so existence never leaks.

**Allow-list payload** (`PublicStoreResource`): `id` (UUID only), `slug`, `name`,
`locale` {language, currency, timezone}, `branding` {logo/banner/favicon URLs,
colours, theme}, `seo` {meta title/description/keywords, canonical, robots},
`contact` {email, phone, address, support_hours}, and **`extensions`**. No internal
id, `organization_id`, settings, audit or private config.

**Composition (ADR-036):** `extensions` is where other modules' contributions
land, each under its own `key`. A future Product/Review/Campaign/Stats module
implements the Core `StorefrontContributorContract` and registers via
`StorefrontRegistry`; `PublicStorefrontAssembler` resolves and merges them —
Store depends on none of them, and a contributor that throws is dropped, never
breaking the core. New sections are new keys; the envelope shape is stable.

## 12.2 Seller (auth:sanctum, throttle:api, membership-scoped) ✅ DONE
| Method | Path | Gate |
|---|---|---|
| GET | `/stores` | scoped to the actor's orgs via `organizationIdsForUser` (never the whole table) |
| GET | `/stores/{store}` | `view` — active member |
| PATCH | `/stores/{store}` | `update` — `store.manage` |
| PATCH | `/stores/{store}/settings` · `/branding` · `/seo` · `/contact` · `/localization` | `store.manage` |
| POST | `/stores/{store}/activate` · `/pause` · `/resume` · `/close` | `store.manage` |

`{store}` binds by UUID. Every route is gated by `StorePolicy` through the Core
`OrganizationAuthorizationContract` — a member of another org is denied by
construction. Controllers are thin (request → policy → action → resource); no
authorization or business logic in them. Split across
`StoreController` / `StoreProfileController` / `StoreLifecycleController` to keep
constructor dependencies within bounds. *(No domain routes — ADR-035.)*

## 12.3 Admin (throttle:panel, permission-gated) ✅ DONE — PLATFORM-LEVEL ONLY
| Method | Path | Permission |
|---|---|---|
| GET | `/admin/stores` | `store.view_any` |
| GET | `/admin/stores/{store}` | `store.view` |
| POST | `/admin/stores/{store}/suspend` | `store.suspend` (reason required, audited) |
| POST | `/admin/stores/{store}/reinstate` | `store.reinstate` |
| POST | `/admin/stores/{store}/archive` | `store.archive` |

Admins **view and enforce**; they never manage a store's content (that is the
seller surface). Gated by `store.*` Spatie permissions via `StorePolicy`.

---

# 13. Filament ✅ DONE

**Explicit per-panel registration** (never shared discovery). Two separate
resources, presentation-only, every operation delegating to a module Action and
gated by `StorePolicy` — no capability check re-implemented in Filament:

- **Admin `StoreResource`** — supervisory only: list + `suspend`/`reinstate`/
  `archive` row actions (each gated by the `store.*` permission via the policy).
  `canCreate() = false` (creation is event-driven). No content management.
- **Seller `StoreResource`** — membership-scoped: `getEloquentQuery()` confines
  rows to the actor's orgs via the Core `OrganizationAuthorizationContract` (no
  Organization import, ADR-033); lifecycle row actions (`activate`/`pause`/
  `resume`/`close`) gated by `StorePolicy`. `canViewAny()` is overridden to true
  because `StorePolicy::viewAny` is admin-semantics — per-record authorization
  stays in the policy and the query is the tenancy wall. Rich profile editing is
  driven through the API/Next.js dashboard, not duplicated as Filament forms.

---

# 14. Notifications

Core `BaseNotification` (mail + database). `StoreSuspendedNotification` (owner),
`StoreDomainVerifiedNotification`, `StoreDomainVerificationFailedNotification`.
EN + TR.

---

# 15. Audit

`Auditable` on `Store`, `StoreDomain`, `StoreSettings` (before/after). Admin
suspend/reinstate carry a `reason` (ADR-027). Verification tokens are excluded
from audit (a domain-control secret). Correlation stitches
`StoreOpeningApproved` → `StoreCreated` into one incident across contexts.

---

# 16. Tests

| Suite | Kind | Asserts |
|---|---|---|
| `StoreCreationTest` | Feature | `StoreOpeningApproved` creates a Draft store; **idempotent** on request UUID (replay → one store); `StoreCreated` fired; carries organization_id/uuid |
| `StoreLifecycleTest` | Feature | activate / pause / resume / close / suspend / reinstate / archive; reinstate restores exact prior state |
| `StoreIsolationTest` | Security | a member of another org cannot view/manage a store (ADR-030); `StoreManage` by role; admin suspension by permission |
| `StoreProfileTest` | Feature | creation seeds empty settings/branding/seo/contact; PATCH updates touch only sent fields; localization writes the Store's locale |
| `StoreApiTest` | Feature | seller list scoped to member orgs; manage/lifecycle for a manager; cross-org 403; unauth 401; admin suspend/reinstate/archive permission-gated |
| `PublicStorefrontTest` | Feature | slug/path render; non-active/missing → 404; allow-list; contributor `extensions` composed |
| `StoreSecurityTest` | Security | public allow-list (no internal id/private field); existence non-disclosure; tenant isolation; admin-permission gating |
| `PublicStorefrontTest` | Feature | resolves by slug/path (ADR-035); only Active renders; allow-list payload |
| `StoreArchitectureTest` | Arch | no import of Organization internals (only its events, ADR-033); repos↔contracts; enums; Domain purity |
| `StoreAuditTest` | Feature | suspend/reinstate audited with reason |
| *(StoreDomainTest)* | — | *deferred with custom domains (ADR-035)* |

Unit tests have no DB (the platform rule); creation/lifecycle are Feature.

---

# 17. Sprint Plan

### Phase 0 — Prerequisites
Ratify ADR-032/033/034 (record + amendment log); rule on locale inheritance
(§4.3) and the membership-check mechanism (§9.1); `LayeringTest` Store rule +
mutual `Domain\Events` exceptions; scaffold module + provider.

### Phase 1 — Store core + creation ✅ DONE
`Store` + `StoreStatus`; the idempotent `StoreOpeningApproved` listener +
`StoreCreated`; repository; Organization back-reference; `StoreCreationTest`.

### Phase 2 — Operational state ✅ DONE
Lifecycle actions (activate/pause/resume/close) + admin suspend/reinstate +
archive; lifecycle events; the Core `OrganizationAuthorizationContract` +
`StoreQueryContract`; slug/number generators behind contracts; `StorePolicy`;
`StoreLifecycleTest` + `StoreIsolationTest`.

### ~~Phase 3 — Domains~~ REMOVED (ADR-035)
Custom domains are out of v1 scope. A future dedicated ADR adds `StoreDomain` +
DNS verification + host resolution additively. **The phases below are renumbered.**

### Phase 3 — Storefront customization ✅ DONE
`StoreSettings` (Auditable), `StoreBranding` (Media, public logo/banner/favicon),
`StoreSeo`, `StoreContact` — 1:1 companions seeded empty at creation; PATCH update
actions + DTOs; `UpdateStoreLocalizationAction` (default lang/currency/tz on the
Store); `StoreProfileTest`. *(Published-languages `StoreLanguage` deferred — a
single default locale covers v1; add when multi-language publishing is needed.)*

### Phase 4 — Public storefront (ADR-034/035/036) ✅ DONE
Core composition seam (`StorefrontContext`, `StorefrontContributorContract`,
`StorefrontRegistry`); `findPublishedBySlug`; `PublicStorefrontAssembler`;
allow-list `PublicStoreResource` with `extensions`; `PublicStoreController`;
`throttle:storefront`; routes `/store/{slug}` + `/magaza/{slug}`;
`StorefrontException` (404, no existence leak); `PublicStorefrontTest`.

### Phase 5 — Seller & Admin API ✅ DONE
Seller surface (index scoped by `organizationIdsForUser`; show/update; profile
settings/branding/seo/contact/localization; activate/pause/resume/close) — all
policy-gated via the Core contract, thin controllers. Admin surface (index, show,
suspend/reinstate/archive) — platform-level, `store.*` permissions.
`StoreResource` (private view); FormRequests authorising via `StorePolicy`;
`StoreApiTest`.

### Phase 6 — Filament (admin + seller, per-panel) ✅ DONE
Admin `StoreResource` (supervisory: list + suspend/reinstate/archive) + seller
`StoreResource` (membership-scoped: list + activate/pause/resume/close) — both
presentation-only, delegating to Actions, policy-gated; registered explicitly per
panel; EN+TR labels.

### Phase 7 — Hardening + freeze ✅ DONE
Expanded `StoreArchitectureTest` (Domain purity, controllers/resources final);
`StoreSecurityTest` (allow-list, existence non-disclosure, tenant isolation,
admin-permission gating); `StoreAuditTest` (suspension reason recorded, lifecycle
audited); docs updated; **module frozen v1.0** (see banner).

---

# 18. Acceptance criteria (module complete)

- A Store exists **only** after an approved request; creation is idempotent.
- Store never imports Organization internals — only its events (ADR-033).
- The public storefront renders only Active stores and leaks no internal
  id/private field (ADR-034).
- Custom domains serve only when DNS-verified.
- Every admin action is audited with a reason; verification tokens never audited.
- Isolation holds: a member of one org cannot touch another org's store.
- `make check` passes.

---

# 19. Reading order for the implementer

1. This document, §0 (the three ADRs + open items) first.
2. `docs/Architecture_Decision_Record.md` — esp. ADR-028 (the creation contract),
   ADR-030 (isolation), ADR-027 (audit), plus 032–034 once ratified.
3. `docs/modules/Organization.md` §7 (Store Opening Requests) — the producer side.
4. `docs/modules/Identity.md` / `Organization.md` as the pattern and rigor bar.
5. `docs/audit.md` before any `Auditable` model; `docs/media.md` before branding.

---

# 20. Forward-looking architecture — Store as a foundation

Per the directive to *think beyond today's scope and surface (not silently
introduce) the abstractions the future Product/Catalog/Offer/Order/Payment
modules will need*. Each item below is labelled **BUILD NOW**, **BUILD THIS
SPRINT** or **DEFER**, with the reasoning. Nothing here is coded until it is
approved; the **DEFER** items are recorded so a later sprint doesn't re-discover
them.

## 20.1 Core `OrganizationAuthorizationContract` — BUILD (Phase 2)

The approved §9.1 mechanism, and the **most important** forward-looking piece.

- **Location:** `app/Core/Domain/Contracts/OrganizationAuthorizationContract.php`
  (Core, so any module may depend on it without importing Organization).
- **Shape (capability by string — Core cannot reference Organization's enum):**
  - `isActiveMember(int $userId, int $organizationId): bool`
  - `hasCapability(int $userId, int $organizationId, string $capability): bool`
  - `capabilitiesFor(int $userId, int $organizationId): array` *(string values)*
- **Implementation:** `App\Modules\Organization\Infrastructure\...` bound as a
  singleton in `OrganizationServiceProvider`. Organization is the single source
  of truth; the capability string maps to its `OrganizationCapability` matrix.
- **Reuse:** the standard cross-context authorization mechanism for **every**
  future seller-owned module. Product/Order ask the same contract
  `hasCapability(userId, orgId, 'product.manage')` etc.
- **Why now:** without it Store cannot authorize seller actions without either
  importing Organization (breaks ADR-033) or duplicating membership data (which
  the ruling explicitly forbids).

## 20.2 Organization capability additions — RULED (option A, 2026-07-23)

For the contract in §20.1 to answer the domain-management question,
Organization's `OrganizationCapability` enum + `OrganizationRole` matrix gained
`StoreManage` / `StoreManageDomains` (a permitted, Store-required change to the
frozen module). **A fork was surfaced** — the original mapping named an
"Organization Admin" role that does not exist in the frozen role model — and
**ruled: no Admin role is introduced.** Final mapping:

| Capability | Roles |
|---|---|
| `StoreManage` | Owner, Manager |
| `StoreManageDomains` | **Owner only** |

Rationale: general storefront operations (products, catalog, orders) may be
delegated to a Manager; managing public identity (domains, DNS, verification) is
an Owner responsibility because it carries platform-wide security and business
implications. Only `StoreManage` is added to the Manager matrix row; the Owner
grants every capability implicitly. **If a future need genuinely requires an
Organization Admin tier, it comes via a separate ADR — not by expanding the
frozen model now.**

## 20.3 Core `StoreQueryContract` — BUILD THIS SPRINT (symmetric read)

The mirror of §20.1 pointing **downstream**: future modules (Product needs "does
this store exist / is it live / which org owns it?") must read Store state
without importing Store (ADR-033).

- **Location:** `app/Core/Domain/Contracts/StoreQueryContract.php`.
- **Shape:**
  - `exists(string $storeUuid): bool`
  - `isLive(string $storeUuid): bool`  *(Active + a verified serving domain)*
  - `organizationIdFor(string $storeUuid): ?int`
- **Implementation:** Store's Infrastructure; bound in `StoreServiceProvider`.
- **Why this sprint:** it costs almost nothing to publish alongside the Store
  aggregate, and it fixes the contract the *next* module will consume — so
  Product never tempts a developer to `use App\Modules\Store\...`. Built once
  Store's status/`isLive` is stable (Phase 2–3), not Phase 1.

## 20.4 Store lifecycle events — BUILD (already in §8)

`StoreCreated / Activated / Paused / Closed / Suspended / Reinstated / Archived`
are the downstream integration seam (e.g. Catalog hides a suspended store's
listings). Already specified; no new decision — flagged here as the second half
of the "future modules integrate by events + Core query contract, never by code"
pattern (§20.1 for authz reads, §20.3 for state reads, §20.4 for state changes).

## 20.5 Shared `BelongsToStore` trait / store-scoping — DEFER

Future store-owned models (products, offers, orders) will all carry `store_id`
and want a consistent scope. A `App\Shared\Traits\BelongsToStore` (store_id +
`scopeForStore`) would standardise it. **Deferred** — building it before a real
consumer exists risks guessing the shape wrong; introduce it with the first
module that needs it (Product), informed by the real access pattern. Recorded so
that module doesn't reinvent it.

## 20.6 `StoreContext` request resolver — DEFER

A request-scoped "current store" resolver (host/slug → Store) is needed by the
public storefront (§5) and later by store-scoped selling APIs. **Build the
minimal host-resolution middleware for the public surface in Phase 5**; a
generalised `StoreContext` shared with future modules is **deferred** until a
second consumer exists, to avoid over-abstracting from one use case.

## 20.7 Summary table

| Extension point | Where | Decision | Status |
|---|---|---|---|
| `OrganizationAuthorizationContract` | Core | **Build** (approved §9.1) | ✅ built + bound (Phase 2) |
| — its Organization implementation | Organization | **Build** | ✅ built + bound (Phase 2) |
| Organization `StoreManage*` capabilities | Organization (frozen) | **Ruled** (option A, §20.2) | ✅ added (Owner/Manager · Owner-only) |
| `StoreQueryContract` | Core | **Build** | ✅ built + bound (Phase 2) |
| Store lifecycle events + transition actions | Store | **Build** (specified) | ✅ built (Phase 2) |
| Slug/number generators behind contracts | Store | **Build** (approved) | ✅ built; removed from aggregate (Phase 2) |
| `StorePolicy` | Store | **Build** | ✅ built + registered (Phase 2) |
| `BelongsToStore` trait | Shared | **Defer** to first consumer | — |
| `StoreContext` resolver | Core/Shared | **Defer**; minimal middleware only | Phase 5 |

## 20.8 Phase 2 build status — COMPLETE

**Built and lint-clean:**

- `StoreStatus` transitions (activate/pause/resume/close/suspend/reinstate/
  archive) as one action each, emitting `StoreActivated/StorePaused/StoreClosed/
  StoreSuspended/StoreReinstated/StoreArchived`; a `status_before_suspension`
  column so reinstatement restores the exact prior state.
- `StoreQueryContract` (Core) + `StoreQuery` impl — the downstream read port.
- `StoreSlugGeneratorContract` + `StoreNumberGeneratorContract` with default
  impls; generation removed from the Store aggregate.
- Core `OrganizationAuthorizationContract` + Organization's `OrganizationAuthorization`
  impl; `StoreManage` (Owner/Manager) and `StoreManageDomains` (Owner-only) added
  to `OrganizationCapability` + the Manager matrix row.
- `StorePolicy` (seller ops via the contract; admin suspend/reinstate/view via
  `store.*` permissions) registered; `StoreLifecycleTest` + `StoreIsolationTest`.
