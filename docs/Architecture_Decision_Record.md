# MarketplaceOS Architecture Decision Record (ADR)
Version: 1.0

This document records all approved architecture decisions.

If any document conflicts with this file, this file takes precedence until the affected documents are updated.

---

# ADR-001 Architecture Documents

Authoritative architecture document:

docs/001_Architecture.md

Legacy document:

docs/architecture.md

Decision

- Migrate any missing architectural decisions from the legacy document.
- Preserve the amendment history.
- Update cross references.
- Remove the legacy document after migration.

---

# ADR-002 Foundation Structure

Foundation is NOT a single module.

Foundation is a module group.

Modules:

- Identity
- Localization
- Settings
- Audit
- Activity
- Media
- Notification

Each module owns:

- Models
- Migrations
- Services
- Policies
- Events
- Jobs
- DTOs
- Tests
- Documentation

No Foundation module should physically exist.

---

# ADR-003 Document Priority

Document precedence:

1. CLAUDE.md
2. Architecture_Decision_Record.md
3. 001_Architecture.md
4. 003_Database_Standards.md
5. 002_Coding_Standards.md
6. 004_Naming_Conventions.md
7. 005_API_Standards.md
8. Module Specifications

Sprint prompts never override documentation.

---

# ADR-004 Primary Keys

Database primary keys:

BIGINT

Public identifiers:

UUID

UUID is never used as a foreign key.

---

# ADR-005 Money Storage

Money values are stored as integer minor units.

Examples

1299.90 TL

↓

129990

Use DECIMAL only for:

- Exchange rates
- Tax rates
- Commission percentages
- Discount percentages

API responses format money as decimal strings.

---

# ADR-006 Lookup Tables

Lookup tables:

Countries

Currencies

Languages

Timezones

Payment Methods

Shipping Methods

Notification channels are NOT lookup tables.

---

# ADR-007 Enum Naming

Enum names do NOT use the Enum suffix.

Correct

OrderStatus

OfferStatus

StoreStatus

Wrong

OrderStatusEnum

OfferStatusEnum

---

# ADR-008 API JSON Format

API responses use snake_case.

Example

current_page

per_page

created_at

updated_at

Never use camelCase in REST responses.

---

# ADR-009 API Error Format

Canonical error response:

{
    "success": false,
    "code": "VALIDATION_ERROR",
    "message": "Validation failed.",
    "errors": {
        "email": [
            "The email field is required."
        ]
    }
}

Errors are included only when applicable.

---

# ADR-010 Service Layer

Services may return:

- DTO
- Value Object
- Domain Result

Presentation layers (Filament, Console, Admin UI) may use Eloquent models when appropriate.

REST APIs must never expose Eloquent models directly.

---

# ADR-011 Domain Models

Domain models may contain:

- Relationships
- Accessors
- Mutators
- Scopes
- Lightweight helper methods

Business workflows belong to Services.

---

# ADR-012 User Model

User fields:

- first_name
- last_name

Email uniqueness:

Composite uniqueness:

(type, email)

Allows the same email address to exist across different account types.

---

# ADR-013 Roles

Current default roles:

- Super Admin
- Admin
- Editor
- Support
- Finance
- Seller
- Seller Employee
- Customer
- Category Manager

Category Manager remains part of the system.

---

# ADR-014 Soft Delete

Business entities use Soft Delete.

Cascade delete is allowed only for dependent child records such as:

- Sessions
- Devices
- Temporary tokens
- Pivot tables

Business entities must never be cascade deleted.

---

# ADR-015 Lookup Table Status

Lookup tables use:

is_active

Business entities use:

status

Lookup tables do not require workflow states.

---

# ADR-016 Audit Columns

created_by and updated_by are mandatory for new business tables.

Existing tables may be migrated incrementally.

Avoid large-scale migration solely to satisfy this standard.

---

# ADR-017 Foundation Scope

Foundation implementation includes:

- Authentication
- Authorization
- Users
- Localization
- Countries
- Languages
- Currencies
- Settings
- Media
- Notifications
- Audit
- Activity

Deferred:

- Bulk APIs
- Webhooks
- Idempotency Keys
- Advanced Search
- External Providers

---

# ADR-018 Documentation

Documentation is considered executable architecture.

Implementation must never contradict approved documentation.

If ambiguity exists:

STOP.

Report.

Wait for approval.

Never guess.

---

# ADR-019 Domain Layer Helpers

Supersedes the unqualified rule in 002_Coding_Standards §30.

Allowed in the Domain layer:

- now()
- config()

Forbidden in the Domain layer:

- cache()
- request()
- encrypt()
- decrypt()

Rationale

now() is a clock reading and is controllable in tests via travelTo().

config() reads a static array. No I/O, no state.

The forbidden four perform real infrastructure work — I/O, request state, key
material — and are what make a Domain class untestable without booting the
framework.

Placement

Caching belongs to Infrastructure (Repositories or dedicated services).

Encryption belongs to Infrastructure.

HTTP request access belongs to the Presentation layer.

Scope note

The rule covers global helper functions as well as Facade classes. A helper that
resolves the same container binding is the same violation.

---

# ADR-020 Class Size

Supersedes 002_Coding_Standards §23.

The 300-line class limit is NOT a hard rule.

It is a review threshold. A class exceeding 300 lines requires architectural
review and documented justification.

Permanently exempt, with justification recorded:

- Framework interface implementations
- Aggregate roots (such as User)

The following remain STRICT:

- Maximum 50 lines per method
- Maximum 7 constructor dependencies
- High cyclomatic complexity must be refactored

Rationale

Line count tracks comprehensibility poorly at the class level and well at the
method level. A class that is long because an approved architectural decision
made it long is not improved by splitting it into pieces that must be read
together.

---

# ADR-021 DTO Naming

Official suffix:

DTO

Examples

LoginDTO

RegisterUserDTO

CreateProductDTO

Directory

Domain/DTOs

Rename Domain/Data to Domain/DTOs in every module.

The term "Data" is forbidden as a DTO class suffix.

"DataTransferObjects" is allowed only for shared infrastructure if necessary —
it names a pattern rather than describing a vague noun.

---

# ADR-022 Documentation Naming

Supersedes 004_Naming_Conventions §29.

Existing documentation files are NOT renamed.

The standard reflects the existing structure:

Governing documents

NNN_PascalCase_With_Underscores.md

Example: 001_Architecture.md

Architecture Decision Records

PascalCase_With_Underscores.md

Example: Architecture_Decision_Record.md

Topic and module documentation

lowercase-with-hyphens.md

Example: authentication.md, error-handling.md

Directory READMEs

README.md

Rationale

The numeric prefix on governing documents encodes precedence order, which is
load-bearing information. Renaming 16 topic documents and rewriting 68 inbound
links buys nothing — no tool consumes these filenames.

---

# ADR-023 ORM Metadata in Domain Models

Narrows ADR-019 and 001_Architecture §5.

Infrastructure-specific Eloquent metadata MAY be referenced by Domain models
when required by the ORM.

Allowed

- Custom Casts
- Observers
- Global Scopes

These references must remain DECLARATIVE ONLY.

Business logic must never depend on Infrastructure services.

Domain models must NEVER reference:

- Services
- Repositories
- Cache
- HTTP
- Mail
- Queue
- Crypt

Rationale

This exception exists solely because Laravel Eloquent is an Active Record ORM.
A cast must be declared on the model it applies to; there is no other seam. The
alternative was to leave encrypt()/decrypt() inline — which ADR-019 forbids — or
to push decryption into a service, which would make Setting::typedValue() return
ciphertext to every other caller.

The test

Naming a class in casts(), observe() or addGlobalScope() is metadata.
Calling a method on an Infrastructure service is a dependency. The first is
allowed; the second is not.

Sanctioned use

App\Modules\Settings\Domain\Models\Setting names
App\Modules\Settings\Infrastructure\Casts\EncryptedSettingValue in casts().

---

# ADR-024 auth() in the Domain Layer

ADR-019 is NOT extended to include auth().

auth() represents the authenticated Identity context, not infrastructure
access. It is acceptable in the current architecture.

Sanctioned use

App\Modules\Audit\Domain\Concerns\Auditable resolves the causer through
current_actor(), which reads auth().

Note

This is a deliberate scope decision, recorded so the question is not reopened.
The forbidden list in ADR-019 remains exactly: cache(), request(), encrypt(),
decrypt().

---

# ADR-025 Out-of-Band Credential Delivery

REVOKES the token-in-response example approved as Identity specification Q2.

Rule

The backend must NEVER return password reset tokens or email verification
tokens in any API response.

Reset and verification tokens are SECURITY CREDENTIALS. They must travel only
through an out-of-band channel — email.

Rationale

A token returned from an unauthenticated endpoint is not a credential, it is a
public value. Anyone knowing an email address could obtain a valid reset token
and seize the account in two requests. Rate limiting does not help: one request
is enough.

It also breaks the non-enumeration rule. A response carrying a token for real
accounts and none for missing ones is an existence oracle.

Frontend-agnosticism, preserved

The original concern was correct: the backend must not hardcode frontend URLs.
That is solved with configuration, not by exposing the token.

    marketplace.frontend.url
    marketplace.frontend.password_reset_path
    marketplace.frontend.email_verify_path

    FRONTEND_URL=https://site.com
    FRONTEND_PASSWORD_RESET_PATH=/reset-password/{token}
    FRONTEND_EMAIL_VERIFY_PATH=/verify-email/{id}/{hash}

The notification composes the final URL from configuration only. A second
frontend needs one environment value, not a backend change.

Response contract

POST /auth/password/forgot returns an identical envelope whether or not the
account exists:

    {
        "success": true,
        "message": "If an account exists for this email address, password reset
                    instructions have been sent."
    }

No token. No user information. No timing differences.

The same rule applies to email verification.

Scope

This is platform-wide, not Identity-specific. It binds every credential a
future module issues — organization invitations, API keys, webhook secrets.

If a credential grants access, it leaves through the channel its owner
controls, never through the response body of the request that created it.

---

# ADR-026 Shared Security Primitives Live in Core

Cross-cutting security primitives are placed in `app/Core` behind a contract,
never inside the module that first needs them.

First instance: the one-time-password store.

- `App\Core\Domain\Contracts\OtpStoreContract`
- `App\Core\Infrastructure\Otp\CacheOtpStore`

Rationale

OTP is needed by more than Identity. It will back email-verification fallback,
sensitive-action confirmation, store-ownership verification, organization
invitations and high-risk-operation confirmation. If it lived in Identity,
every one of those modules would have to import Identity — which the layering
rule forbids (§5).

Core is depended on by every module (§5: modules → Core → Shared), so a
primitive placed there is reachable everywhere without a cross-module import.

The rule

If a security primitive is generic — it operates on an opaque identifier and
carries no business meaning — it belongs in Core. The *usage* stays in the
module: Identity owns its `EmailOtpNotification` and its 2FA flow; it borrows
only the store.

Related abstractions applying existing rules (no separate ADR):

- **TOTP provider** — `TotpProviderContract` in Identity, so no service or
  controller depends on `Google2FA` directly (§13.1, depend on contracts).
- **Recovery codes** — count, length and hash algorithm are configuration, not
  hardcoded (`002` §16).

---

# ADR-027 Audit Is the Platform's Forensic Event Store

The audit trail records **every forensic event**, not only model changes. A row
carries a generic `event_type` and a `severity` that is **independent of the
type**. Model create/update/delete are one category among many.

Superseded framing

The original table answered one question — "what changed on this record?" Its
whole vocabulary was `created|updated|deleted|restored`, each tied to a model
diff. That made a whole class of forensic events unrecordable: a detected
brute-force login has an actor, an IP and a severity, but **nothing changed on
a record**, so there was nowhere to put it. Q6's "high-severity audit event"
had no shape to take.

The decision

- `event_type` — `App\Modules\Audit\Domain\Enums\AuditEventType`. Categories:
  `MODEL_*` (lifecycle), `SECURITY_*` (login, brute force, credential
  stuffing), and governance seams declared ahead of their emitters
  (`PERMISSION_CHANGED`, `COMMISSION_CHANGED`, `PAYMENT_CONFIGURATION_CHANGED`,
  `STORE_TRANSFERRED`).
- `severity` — `App\Modules\Audit\Domain\Enums\AuditSeverity`:
  `INFO · NOTICE · WARNING · HIGH · CRITICAL`. Independent of type, because the
  same type is routine in one context and an incident in another — a login is
  `INFO`, a login *storm* is `HIGH`. It maps onto PSR/syslog levels so a SIEM
  export needs no translation table.
- `metadata` (jsonb) — context for events that are **not** a model diff, where
  `old_values`/`new_values` are meaningless. The email an attacker targeted,
  the failure count, the distinct-IP count.
- `event`, `auditable_type`, `auditable_id` become **nullable**. A security
  event has no model verb and may name no record at all — an attack on an
  address that was never registered.

Model changes are unaffected: the `Auditable` trait now stamps `MODEL_*` at
`INFO` alongside the diff it already wrote. Standalone events come through a new
`App\Modules\Audit\Application\Services\AuditLogger`.

Audit vs Activity, restated

Audit is the **immutable forensic log** — evidential, 730 days, the SIEM feed.
Activity is the **user's own timeline** — narrative, 365 days. A suspicious
login is written to *both*: forensic evidence in Audit, and a "someone tried to
get in" line the account owner sees in Activity.

Consumer direction (layering)

Audit now **subscribes** to security events raised across the platform, exactly
as Activity subscribes to Identity's. The producer announces and stays ignorant
of the trail; Audit imports the producer's `Domain\Events` and nothing else.
The layering test is amended to permit this precisely — a model, service or
action import from another module is still a leak and still fails
(`tests/Architecture/LayeringTest.php`).

Cost

The audit table is no longer a pure model-diff log; readers must filter on
`event_type` to get the old "just the changes" view (`scopeSecurity`,
`scopeAtLeastSeverity` exist for the new views). Widening Audit's remit means
every future producer of a `SECURITY_*` event adds a subscription, and the
layering exception widens module-by-module — each addition a reviewed decision,
not a blanket opening.

---

# ADR-028 Stores Are Created Only by Admin Approval of a Request

An Organization may **never** create a Store directly. It may only submit a
**Store Opening Request**. An administrator reviews the request. A Store is
created **only** after approval. Store creation is **never** automatic.

This is the canonical rule for the Organization ↔ Store relationship.

The workflow

```
Organization ── submits ──▶ StoreOpeningRequest (Pending)
                                   │
                              Admin reviews
                              ┌────┴────┐
                         Approved     Rejected
                              │            │
                StoreOpeningApproved   StoreOpeningRejected   (domain events)
                              │
                 the Store module creates the Store
```

The boundary

- `StoreOpeningRequest` is owned by **Organization**. The `Store` model is owned
  by the **Store** module. Organization never imports Store and never
  instantiates a Store — it announces `StoreOpeningApproved`, and Store (a
  future module) subscribes and creates the storefront. This keeps the two
  modules isolated and the direction one-way (§4, events between modules).
- An approved request references the created store by **UUID**
  (`created_store_uuid`), never a foreign key — Organization must not have a
  schema dependency on a table it does not own.

Store limits

An Organization has a maximum number of Stores. The architecture supports **both
a system-wide default and an organization-specific limit**, resolved first
non-null wins:

1. per-organization override (`organizations.store_limit_override`)
2. the Organization's plan limit (`OrganizationPlan.store_limit`, null = unlimited)
3. system default (`config('marketplace.organization.default_store_limit')`)

Plans (Starter → 1, Business → 5, Enterprise → unlimited) are a **lookup table**,
operator-configurable without a release. The limit is enforced fail-fast at
submission and **authoritatively at approval** — the plan or override may change
while a request sits pending, so approval is the binding gate.

Rationale

A marketplace's trust rests on every storefront having passed a human check. An
automatically-created Store is an automatically-created liability — a fraudulent
or malformed shop live before anyone looked. The two-step flow makes "a Store
exists" imply "an admin approved it," always.

Cost

Slower store creation for the seller and a standing admin review queue. That
latency is the price of the guarantee, and it is deliberate. The seller-facing
UX must make the pending state legible so the wait does not read as a failure.

Full specification: [docs/modules/Organization.md](modules/Organization.md).

---

# ADR-029 Organization Ownership

An Organization has **exactly one Owner**, always. The Owner **cannot be
removed**. Ownership is changed only by **transfer** to another active member,
and only after a successful transfer does the previous Owner lose the Owner
role. An Organization can never exist without an Owner.

Enforcement (defence in depth)

1. **Schema** — `organizations.owner_id` is NOT NULL with `restrictOnDelete`: the
   database refuses to delete a user who owns an org, and refuses an org with no
   owner.
2. **Transfer is atomic** — `TransferOwnershipAction` promotes the target member
   to Owner and demotes the current Owner to a chosen role in one transaction,
   then repoints `owner_id`. There is no intermediate state with zero or two
   Owners. The target must be an **active member** and a **Seller** (a Seller
   Employee cannot own).
3. **Removal is not a transfer** — removing the Owner's membership is refused by
   both the policy and the action while they are the Owner. The only path out of
   ownership is a transfer.

Rationale

An ownerless company is a company nobody is accountable for — no one to receive
payouts, answer a dispute, or authorise a change. Making "no Owner" and "Owner
removed" unreachable states means every Organization always has a responsible
principal. Two Owners is equally forbidden: it splits accountability and doubles
the privilege surface.

Cost

Ownership changes take a deliberate, audited transfer rather than an edit — the
outgoing Owner cannot simply "leave." That friction is the point.

# ADR-030 Organization Isolation (multi-tenancy)

A User may belong to **multiple Organizations**. Each membership carries its
**own role**. All Organization-scoped data is **isolated by `organization_id`**,
and **no Organization may access another's resources**.

**This is a platform-wide rule.** It binds every future seller-owned module —
Store, Product, Offer, Inventory, Order, Shipping, Campaign, Dashboard and any
other — not just Organization. A resource that belongs to an organization must:

- carry an `organization_id` (directly, or transitively through its parent), and
- be filtered by the acting membership's `organization_id` on **every** read and
  write, in the repository/policy layer, never left to the caller.

Membership, not the platform guard, is the tenancy boundary: a Seller
authenticated at the platform level still sees only the organizations they are a
member of, and within one, only that organization's data. `owns()` in the
seller-facing policies resolves to "is an active member of THIS resource's
organization," and the repositories scope every query by it.

Rationale

Multi-vendor marketplaces leak sideways when tenancy is an afterthought: one
seller enumerating another's orders by id is the classic breach. Making
`organization_id` scoping a load-bearing, tested rule from the first module means
later modules inherit isolation instead of re-deriving (and mis-deriving) it.

Supersedes: the Organization spec's "a seller owns at most one" (§11) — a user
may own or belong to several. `owner_id` remains the canonical single Owner **per
organization**; "which organizations does this user belong to" is a membership
query.

Cost

Every seller-facing query and policy carries an organization scope; forgetting it
is a cross-tenant leak, so it is enforced by architecture/security tests, not
convention. Cross-organization features (if ever needed) become explicit,
audited exceptions rather than the accidental default.

Full specification: [docs/modules/Organization.md](modules/Organization.md).

---

# ADR-031 Platform Invitation Architecture

The invitation mechanism is **platform infrastructure, not Organization business
logic**. It lives in `app/Core` (like `BaseNotification` and the OTP store), and
Organization is merely its **first consumer**. Store, Team, Admin and any future
module reuse the same architecture.

The pieces (in Core / Shared)

- `App\Shared\Enums\InvitationStatus` — the shared lifecycle: `Pending`,
  `Accepted`, `Rejected`, `Expired`, `Cancelled`.
- `App\Core\Domain\Contracts\InvitationTokenizerContract` — generates a raw
  token, hashes it, and verifies a presented token against a stored hash.
  `App\Core\Infrastructure\Invitations\Sha256InvitationTokenizer` is the
  implementation (deterministic SHA-256 so a hash can be looked up in O(1);
  high-entropy tokens do not need a salted, per-row hash the way passwords do).
- `App\Core\Domain\Concerns\HasInvitationLifecycle` — a trait a module's
  invitation model uses for the status/expiry transitions and the acceptability
  check. The module owns the model (its FKs, its target role); Core owns the
  mechanism.

The invariants (bind every consumer)

1. **Invitations never create users.** Accepting an invitation attaches an
   existing account to something; it never registers one.
2. **Acceptance requires an authenticated account.** The accept endpoint is
   behind auth. A recipient with no account must **register first, then complete
   the invitation** — the front end routes them through registration and back.
3. **Only the hash is stored.** The raw token is generated, emailed once
   (out-of-band, ADR-025), and never persisted or returned by any API. The
   database holds `token_hash` only; verification hashes the presented token and
   compares.
4. **Single-use and time-bound.** An accepted/expired/cancelled invitation
   cannot be reused; issuing a fresh invitation invalidates the prior pending one
   for the same target.

Rationale

Every module that grows a team needs the same flow, and it is a flow that is
easy to get subtly wrong (leaking a token, letting an unauthenticated caller
accept, auto-creating a shadow account). Building it once, in Core, behind a
tested contract means the second and third consumers inherit the security
properties instead of re-deriving them.

Cost

A consumer cannot bend the mechanism to auto-create a user or return a token —
those doors are closed by the architecture. The register-first detour adds a step
for a brand-new recipient, which is the correct trade for never minting an
account from an email link.

Full specification: [docs/modules/Organization.md](modules/Organization.md) §6.

---

# ADR-032 Event-Driven, Idempotent Store Creation

A Store is created **only** by a listener consuming `StoreOpeningApproved`
(ADR-028) — never by a seller action, never by the Store module itself. Creation
is **idempotent**, keyed on the opening request's UUID: a replay, a redelivery,
or a double-dispatch of the same event creates **one** Store, never two. On
success the listener emits `StoreCreated`.

Enforcement: `stores.opening_request_uuid` is UNIQUE; the listener looks it up
and returns early if a store already exists for that request.

Rationale: ADR-028 makes approval the sole creation trigger; this states the
consumer contract. Event delivery is at-least-once, so idempotency is not
optional — a duplicated storefront is a customer-facing defect and corrupts the
store-limit accounting.

Cost: creation must be written defensively (unique key + upsert-or-skip) and a
creation failure must be observable (the request stays approved but storeless)
and retryable.

# ADR-033 Cross-Context References by id/UUID, Never by Code

A downstream context references an upstream aggregate by its **id and UUID**,
with an optional database FK for integrity, but **never imports the upstream
module's models, services or repositories**. Store persists only
`organization_id` and `organization_uuid`; it has no `belongsTo(Organization)`
relation. Data it needs from upstream arrives in an **event payload** or through
a **published Core query contract** (see ADR — §9.1 mechanism) — never a live
cross-module code call.

**This is platform-wide.** Product, Catalog, Offer, Order and Payment follow the
same rule: reference the parent by id/UUID, read what they need through events or
a Core query contract, import nothing.

Rationale: it generalises the `created_store_uuid` pattern ADR-028 already uses
in the other direction, and it is what keeps `LayeringTest`'s module-isolation
rule enforceable while contexts still relate.

Cost: no `$store->organization->name` convenience; upstream data that must stay
fresh is read through a query contract or refreshed by an event. Denormalised
copies can go stale unless a change event updates them.

# ADR-034 The Public Storefront Is a Distinct, Unauthenticated Read Surface

Store data splits in two, served by two separate surfaces:

- **Public** — name, branding, SEO, public contact, locale. Served **without
  authentication**, resolved by slug/path (`/store/{slug}`, ADR-035), allow-list
  only. Only a live (Active) store renders; internal ids and private fields never
  appear.
- **Private** — settings, operational internals, draft state. Behind the seller
  guard + org capabilities (ADR-030) and the admin guard.

Rationale: a storefront is meant to be seen by anonymous shoppers — the
platform's first public read boundary. Keeping it a separate surface (its own
controllers, resources, throttle and domain-resolution middleware) stops a
private field leaking into a page anyone can load.

Cost: two resource shapes per concept (public vs private) and the discipline that
the public resource is allow-list, never deny-list.

Full specification: [docs/modules/Store.md](modules/Store.md).

---

# ADR-035 Stores Are Addressed by Platform Path in v1 (No Custom Domains)

A storefront is reached **only** through the platform URL structure —
`/store/{slug}` (localised, e.g. `/magaza/{slug}`) — resolved by the store's
globally-unique `slug`. **v1 supports no per-store domains**: no custom domains,
no subdomains, no DNS/TXT verification, no host-based resolution. This scopes
ADR-034's public read surface to slug/path resolution.

The Store model stays **extensible** for domains without rework: the slug is
already the single public identifier, `Store::isLive()` is the one place a
"serving" precondition would tighten, and the authorization port already has the
shape for an Owner-only domain capability. Introducing custom domains later is a
**dedicated future ADR** that adds a `StoreDomain` aggregate, a
`StoreManageDomains` capability (Owner-only — a domain change alters public
business identity), and a host-resolution middleware — additively, breaking
nothing built here.

Rationale: custom domains carry disproportionate complexity (DNS verification,
certificate issuance, host routing, tenant-to-host mapping) for v1. Path
addressing ships the storefront now; the deferral is explicit, not an omission.

Cost: sellers cannot use their own domain in v1; every store lives under the
platform host. Re-introducing domains means the future ADR's migration + model,
not a redesign.

Full specification: [docs/modules/Store.md](modules/Store.md) §5.

---

# ADR-036 The Public Storefront Is Composed, Not Owned

The public storefront is the marketplace's **canonical public entry point** and a
**long-term read contract**, not a single module's page. Store owns only the
storefront **core** — identity, branding, SEO, contact, locale. Everything else a
storefront eventually shows (products, categories, campaigns, reviews, statistics)
is **contributed by the module that owns it**, through composition — Store never
depends on those modules.

The mechanism is a Core seam:

- `StorefrontContributorContract` (Core) — a future module implements it to add a
  section to the public payload: `key(): string` (a namespaced section name) and
  `contribute(StorefrontContext): array` (the section's public data).
- `StorefrontContext` (Core) — a scalar-only context (store id/uuid/slug, owning
  org id, language/currency codes) handed to each contributor, so a contributor
  needs no Store model (ADR-033).
- `StorefrontRegistry` (Core) — modules register their contributor in their own
  service provider, exactly as they register permissions.

Store's public assembler builds the core and merges each registered contributor's
output under `extensions[key]`. Adding products to the storefront is a Product
module that registers a contributor — **no change to Store**.

The public resource is a **strict allow-list**: identity, branding, SEO, contact,
locale, and `extensions`. No internal id (the UUID is the only identifier),
permissions, settings, audit, or private configuration ever appears. The contract
is designed to be *extended additively* — a new section is a new key, never a
reshaped envelope — because a public API that breaks its shape breaks every
deployed client.

Rationale: a marketplace storefront accretes concerns for years. Composition keeps
Store a stable bounded context while the storefront grows, and keeps the public
contract stable while its contents expand.

Cost: contributors run per public request, so each must be cheap or cached; the
registry is another platform seam to understand. A contributor that throws must
degrade to omitting its section, never break the core storefront.

Full specification: [docs/modules/Store.md](modules/Store.md) §12.

---

# ADR-037 The Catalog Is Shared; the Seller↔Product Link Is an Offer, Never a Copy

A **Product is platform-owned and shared**. One canonical entry — title, brand,
category, attributes, images, variants — describes a product for the whole
marketplace, and many sellers sell against it. A seller never receives their own
copy of a product; the seller↔product link is an **Offer** (a later sprint) that
references a catalog Product/Variant **by uuid**.

This is the Trendyol/Amazon/Hepsiburada model, chosen deliberately over the
per-seller-products model (Etsy/Shopify), where every seller owns their own
product rows.

In Phase 1 a seller may only **propose/author** a product into the shared
catalog. The proposal is moderated (ADR-038); nothing about it is private to
that seller once published.

A **Product carries no price and no stock.** That is the boundary the whole
decision rests on: it is exactly what lets one product be sold by many sellers at
different prices without duplication. Price/stock belong to Offer and Inventory.

Rationale: the buyer experience the platform is built for — one product page,
many sellers, a comparable price list — is only expressible if the product is
one row that all sellers point at. Per-seller copies make that page a
reconciliation problem forever.

Cost: deduplication and moderation become first-class problems on day one. Two
sellers proposing "iPhone 15 128GB" must converge on ONE catalog entry, or the
shared catalog degrades into the per-seller mess we rejected. We pay for this
with a moderation lifecycle and GTIN/barcode uniqueness that the simpler model
would not need, plus a Category-Manager workload that scales with seller count.

Full specification: [docs/modules/Catalog.md](modules/Catalog.md) §0.3.

---

# ADR-038 The Taxonomy Is Central and Owned by the Category Manager

Categories form a **tree owned by the platform**, maintained by the existing
**Category Manager** role (ADR-013 — the role was reserved for exactly this).
Each category carries an **attribute schema**: which attributes apply, which are
required, and which are variant-defining *in that category*. The same attribute
(Renk) may be variant-defining in "Giyim" and merely descriptive in "Mobilya".

A product attaches to a **leaf** category and must satisfy that category's
schema. Sellers cannot invent categories, attributes or attribute values.

Tree storage is an **adjacency list plus a materialised `path` column**,
self-owned, with no tree package: writes stay a single parent pointer, and
descendant reads are a path-prefix scan.

Rationale: consistent categories and typed attributes are what make search,
filtering and comparison work at all — the difference between a marketplace and
a flea market. Free-form seller tagging produces a catalog that cannot be
faceted.

Cost: the Category Manager is a throughput bottleneck. Onboarding a genuinely
new kind of product requires a human to extend the taxonomy first, which is
slower than free-form tagging. The materialised path must be rewritten when a
subtree moves — rare, and a bounded update, but it is real write complexity we
accepted over a package dependency or nested-set writes.

Full specification: [docs/modules/Catalog.md](modules/Catalog.md) §0.4, §13.1.

---

# ADR-039 Variants Are First-Class; a "Simple" Product Is a One-Variant Product

A Product has **1..n ProductVariants**. A variant is a unique combination of its
category's **variant-defining** attribute values (Beden=M, Renk=Kırmızı) and
carries the `sku`. The **variant — not the product — is the unit** that Offer,
Inventory, cart lines and order lines will reference.

A product with no variant axes is modelled as a **single `is_default` variant**,
never as a special case in the schema or the code. There is no "simple product"
branch.

Rationale: retrofitting variants later means rewriting Offer, Inventory, cart and
order lines around a new sellable unit — far more expensive than carrying one
join now. Clothing and footwear are unsellable without variants, so "later" was
never really available for this market.

Cost: every read path carries the product→variant join even for single-variant
products, and the authoring UI is meaningfully more complex than a flat product
form (the seller picks values, the platform generates the cartesian product and
lets them prune it).

Full specification: [docs/modules/Catalog.md](modules/Catalog.md) §0.5, §13.4.

---

# ADR-040 The Catalog References Other Contexts by id/UUID (Reaffirms ADR-033)

Catalog **imports no other module's models and is imported by none**. It
references the **proposing organization by uuid only** (`proposed_by_org_uuid`),
for provenance and moderation scoping — there is no `organization()` relation and
no Organization or Store import anywhere in the module.

Downstream contexts (Offer, Inventory, Search, Storefront) reach the Catalog
**only** through the Core `CatalogQueryContract` and the Catalog's domain events,
exactly as they reach Store through `StoreQueryContract` (ADR-033/034).

Rationale: this is ADR-033 applied to the first module built after the Org/Store
freeze. Restating it as its own ADR makes the Catalog's obligation explicit
rather than inherited by implication, and gives the seller-scoping rule
(`proposed_by_org_uuid`, not a membership join) a citable home.

Cost: no `$product->organization->name` convenience; the proposing company's
display name must be resolved through a contract or denormalised on the event
that needs it. Seller scoping is by uuid comparison rather than a FK join, which
is slightly less expressive to query.

Full specification: [docs/modules/Catalog.md](modules/Catalog.md) §0.7.

---

# ADR-041 The Catalog Enriches the Storefront Only Once Products Are Sellable

Per ADR-036 the public storefront is composed from
`StorefrontContributorContract` implementations. **Catalog registers no
storefront contributor in Phase 1.**

A store page shows *its* products, and "its products" means that store's
**Offers** — which do not exist yet. A contributor that listed catalog products
on a store page would be asserting a store↔product relationship the platform has
not defined. The product-listing contributor therefore ships with **Offer**,
which owns that relationship, and Phase 1 touches neither Store nor the
storefront.

Rationale: composition (ADR-036) only helps if each contributor is registered by
the module that actually owns the relationship being displayed. Registering an
empty or guessed one now would fix the section's shape before the owning module
exists.

Cost: the Catalog ships with **nothing buyer-visible**. Phase 1 demos are
admin/seller-facing only, and the platform carries a fully-populated catalog that
no customer can see until Offer ships. We accepted that over building the
sellable surface on a catalog schema that had not yet been exercised.

Full specification: [docs/modules/Catalog.md](modules/Catalog.md) §0.8.

---

# ADR-042 An Offer Is a Priced Listing Against a Variant (One Product, Many Offers)

The sellable unit is the **`ProductVariant`** (ADR-039), not the Product. An
**Offer** is one seller organization's **price (+ optional list price) and stock**
for exactly one variant, referenced by uuid. Many offers may target the same
variant — that is the multi-vendor model the shared catalog exists for. A seller
holds **at most one active offer per variant** (editing re-prices it, never forks
a second). This reaffirms ADR-037: the seller↔product link is an Offer, never a
copy.

Rationale: per-variant, per-seller pricing with no duplication of the catalog is
the defining behaviour of the marketplace; it is why the Catalog was built shared.

Cost: there is no single "product price" — every buyer read that shows a product
for sale must fan a variant out to its competing offers and pick a winner at read
time (ADR-045). We accept that read-time fan-out as the price of the model.

Full specification: [docs/modules/Offer.md](modules/Offer.md) §0.3.

---

# ADR-043 Stock Lives on the Offer This Sprint; Inventory Becomes the Authority Later

The Offer carries a single integer `stock_quantity` the seller sets. Out-of-stock
is **derived** (`= 0`), never a stored status. When the **Inventory** module
ships it becomes the authority for on-hand quantity and reservations, and the
Offer's counter is migrated to / derived from Inventory rather than set directly.

Rationale: the buy box must be able to say "tükendi" today, and a naïve counter is
enough while nothing decrements it — there is no checkout this sprint.

Cost: stock has no reservation semantics for one sprint (two buyers could both see
"1 in stock"), and a migration to Inventory is owed later. The race is harmless
now because nothing checks out; we pay the migration deliberately when
reservations actually matter.

Full specification: [docs/modules/Offer.md](modules/Offer.md) §0.4.

---

# ADR-044 Offers Are Not Moderated — Free, Instant-Live, Admin Reactive Only

Unlike product authoring (a full draft→review→published lifecycle, Catalog §3.1),
an offer goes **live the moment the seller creates or edits it**. The product was
already moderated; price and stock are the seller's commercial freedom. Basic
validation still applies (price > 0, `list_price ≥ price`, published product,
in-scope org, active store) — that is validation, not moderation. Admin oversight
is **reactive**: an admin may `Suspend`/reinstate an individual offer, the same
shape as Store/User suspension.

Rationale: per-offer moderation does not scale (thousands of products × many
sellers, re-priced daily, would drown the Category Manager) and would kill the
"list and sell instantly" value that brings sellers to the platform.

Cost: an absurd or abusive price is visible until someone reacts; there is no
pre-publication gate. Reactive suspension plus later automated price-sanity rules
are the proportionate control we accept instead.

Full specification: [docs/modules/Offer.md](modules/Offer.md) §0.5.

---

# ADR-045 The Buy Box Is Computed, Never Stored

There is no persisted "winning offer" column and no ranking job. The featured
offer for a product is computed at read time: **the cheapest offer that is
`Active` and in stock**, ties broken by earliest `created_at`. Paused,
out-of-stock and suspended offers are excluded from the buy box; withdrawn offers
never appear anywhere.

Rationale: a stored winner would need invalidation on every price/stock/status
change across every competing offer — a cache-coherency problem far more expensive
than a cheap indexed "min price where active and in stock" query. There is no
seller-performance data to rank on yet in any case.

Cost: every product-page read recomputes the winner, and a heavier future buy box
(seller performance, shipping speed) would need real ranking infrastructure we are
choosing not to build now. The explainable min-price rule is what ships.

Full specification: [docs/modules/Offer.md](modules/Offer.md) §0.6.

---

# ADR-046 Offer Ships the Storefront Product-Listing Contributor (Fulfils ADR-041)

Catalog registered **no** `StorefrontContributorContract` (ADR-041) because "a
store's products" means its offers, which did not exist. **Offer now ships that
contributor** (ADR-036): given a store uuid it returns the store's active offers
(product summary + variant + price + in/out of stock), merged by the
`PublicStorefrontAssembler` under its `extensions` key. Store still depends on
Offer for nothing; it composes Offer's contribution through the existing
`StorefrontRegistry`.

Offer imports no other module: it reads Catalog through the Core
`CatalogQueryContract` **plus a new `CatalogBrowseContract`** (a read-only search
over Catalog's existing index for the seller "select a product to sell" flow —
the one sanctioned Catalog change, Catalog being left unfrozen for exactly this);
resolves seller tenancy through `OrganizationAuthorizationContract::
organizationIdsForUser()`; and references the store through `StoreQueryContract`.
All cross-context references are id (internal, tenancy) + uuid (public), reaffirming
ADR-040/033. Downstream (Order, Inventory, Search) reads Offer only through the
Core `OfferQueryContract`.

Rationale: composition (ADR-036) works only if each contributor is registered by
the module that owns the relationship displayed; Offer owns store↔product, so it
owns that contributor.

Cost: the storefront's product section only appears once Offer ships, and the
Catalog gains a second read contract (`CatalogBrowseContract`) it did not need for
its own Phase 1. We accept both as the deferred cost ADR-041 named explicitly.

Full specification: [docs/modules/Offer.md](modules/Offer.md) §0.7.

---

# ADR-047 Product Attachment Is Governed by an Explicit `accepts_products` Flag (Amends ADR-038)

**Amends ADR-038.** A product no longer attaches to a category because it is a
tree **leaf**; it attaches because the category is explicitly flagged
**`accepts_products`**, a per-category boolean the **Category Manager** owns. A
category may carry `accepts_products = true` *and* have children — so a product
can sit at an intermediate level (e.g. *Makyaj*) while that level still has
sub-categories (*Göz Makyajı*). Only categories the Category Manager has NOT
flagged — typically the top-level containers (*Kozmetik ve Kişisel Bakım*) —
refuse products.

Existing data migrates as: every current leaf becomes `accepts_products = true`
(preserving today's behaviour), every current non-leaf `false`. The
attach-validation swaps `category is a leaf` for `category.accepts_products`.

Rationale: the owner needs products at more than one depth without being forced
to the deepest node every time, which the leaf rule made impossible. ADR-038's
real intent — a **central taxonomy the Category Manager controls** — is
preserved: the flag is that control, made explicit, rather than an automatic
consequence of tree shape.

Cost: the automatic guarantee "a category with children never holds products"
is gone; the same product type can now be placed at different depths by
different sellers unless the Category Manager curates the flags. We accept it
because the flag is a *sharper* instrument than the leaf rule — it can forbid a
mid-level container while allowing its sibling — and central curation was always
the model (ADR-038); we trade an automatic invariant for a manual, more
expressive one.

Full specification: [docs/modules/Catalog.md](modules/Catalog.md) §3.2.

---

# ADR-048 Inventory Is the Availability Authority; On-Hand Is Mirrored from the Offer

Inventory becomes the source of truth for **availability** (refines ADR-043). For each
**(seller org, variant)** it holds **on-hand** and **reserved**, and `available =
on_hand − reserved` is what the buy box reads through the Core `InventoryQueryContract` —
no longer `Offer.stock_quantity`.

The **seller still enters stock on the Offer form** (owner decision, 2026-07-29); that
field stays. Inventory keeps on-hand in sync by **subscribing to the Offer's stock events
by class-string** (`OfferCreated / OfferStockChanged / OfferWithdrawn`) — the same
name-not-an-import coupling Offer uses for Catalog — recording each as a seller-adjustment
movement, then layers `reserved` on top.

Rationale: reservations need one authority, and availability (not raw on-hand) is the
question a buyer read actually asks; only Inventory can subtract in-flight holds.

Cost: on-hand exists in two places (the Offer column the seller types, Inventory's mirror),
kept consistent by a synchronous event rather than a shared row — a desync risk we accept
because the mirror is rebuildable from the Offer and the alternatives (Offer importing
Inventory, or moving the seller's entry UI off the Offer against the owner's decision) are
worse couplings. Until Order exists `reserved` is 0, so nothing visibly changes yet.

Full specification: [docs/modules/Inventory.md](modules/Inventory.md) §0.3.

---

# ADR-049 Reservation Primitives Ship as a Core Command Contract, Before Order

Inventory exposes **reserve / release / commit** as a Core **`InventoryReservationContract`**
— a command port, the write-side sibling of the read-only query contracts. Order will be
its first caller; this sprint it is exercised by tests. `reserve` succeeds only when
`available ≥ qty` and raises `reserved`; `release` returns a cancelled hold; `commit`
lowers **both** on-hand and reserved (the units truly leave). All are idempotent on the
caller's `reference`.

Rationale: Inventory precedes Order precisely to give Order a stock authority to call;
shipping the counter without the primitives would make the sprint little more than copying
a number into a new table.

Cost: machinery built and tested with no live caller for a sprint, and a reservation API
committed before Order can exercise it in anger. Accepted as the deliberate cost of
phasing the authority ahead of its consumer.

**Amendment (2026-07-31, owner-approved): the reference is the CALLER'S OWN STRING KEY,
not a uuid.** This ADR shipped `reserve/release/commit` taking a `$referenceUuid`, stored in
a native `uuid` column — on the assumption that a caller would key a hold on something it
already had. ADR-057 then made Order's reference per LINE
(`{order_uuid}:{variant_uuid}`), because a reservation is unique per reference and an order
with two lines sharing one silently leaves the second unheld. Both decisions were right;
together they were a crash — that composite is not a uuid, so **every checkout 500'd on
PostgreSQL** while the suite stayed green on SQLite, where `uuid` degrades to text.

The parameter is now `$reference: string` and the column is `stock_reservations.reference`
(string, still UNIQUE — that index is what makes the three verbs idempotent). Inventory
stores the key and does not interpret it. Callers are asked, but not forced, to keep it
readable: the movement ledger is where a stock dispute is settled, and `{order}:{variant}`
answers "which order, which variant" where a hashed uuid would answer nothing.

Cost: the column name no longer advertises a format, so nothing stops two callers choosing
colliding key schemes — accepted, because the UNIQUE index turns a collision into a loud
failure rather than a silent double-hold. A **pgsql-backed checkout test** now guards the
driver blind spot that hid this.

**Amendment (2026-08-04, Payment P5): a FOURTH verb — `restock`.** The contract shipped
with three verbs because three were all any caller could use: nothing could refund. Payment
P5 can, so the reversal primitive lands with it. `restock($reference)` raises `on_hand` and
leaves `reserved` alone — the hold ended when the sale completed and does not come back —
and it is a no-op on any reference that is not `committed`, which is what stops a retried
refund inventing stock that does not physically exist.

It is deliberately NOT `release` called late. Order.md §12.5 answered this in advance:
Inventory "has no un-commit and must not grow one by side effect — reversing a sale is a
different business event from abandoning a hold, and conflating them in the append-only
ledger makes 'why did my stock go up?' unanswerable". So it carries its own movement type
(`restocked`), its own terminal reservation state (`restocked`) and its own timestamp.

Cost: a fourth verb on a port that was deliberately small, and a fourth movement type on a
ledger whose value is that each row says exactly what happened. Both accepted as the price
of the ledger continuing to answer that question. **This closes Order.md §12.5 follow-up
#1.**

Full specification: [docs/modules/Inventory.md](modules/Inventory.md) §0.4.

---

# ADR-050 The Append-Only Movement Ledger Is the Source of Truth

Every stock change — seller adjustment, reservation, release, commit — is an **append-only
`StockMovement`** (signed `on_hand_delta` / `reserved_delta`, a type, a reference). The
stock record's `on_hand` and `reserved` are **projections**, written in the same
transaction and rebuildable from the ledger. Movements are never updated or deleted — the
Audit/Activity append-only rule (non-negotiable #9) applied to stock.

Rationale: stock disputes are answerable only with a history, and reservations make a bare
counter ambiguous (a drop could be a sale or a hold) — the ledger records which.

Cost: two writes per change and an unbounded ledger, against a single mutable counter.
Accepted for auditability and reservation clarity.

Full specification: [docs/modules/Inventory.md](modules/Inventory.md) §0.5.

---

# ADR-051 Single Stock Pool per (Org, Variant) in v1

Stock is **one pool per (seller org, variant)** — no warehouse/location dimension.
Multi-warehouse returns later, additively, by adding a location to the stock record and the
movement without reshaping the reservation contract.

Rationale: there is no Order or Shipping module for a location to feed, so multi-warehouse
now would be untestable structure with no consumer — the reasoning that kept Offer
single-currency.

Cost: a seller with real multiple warehouses cannot model them yet, and "which location
ships this" has no answer this sprint. Accepted; it returns additively when a consumer
exists.

Full specification: [docs/modules/Inventory.md](modules/Inventory.md) §0.6.

---

# ADR-052 The Cart Is Multi-Seller; Checkout Splits into One Order per Seller

One customer, **one cart**, items from any number of sellers. At checkout the cart is
partitioned by selling org and **each partition becomes its own `Order`** — its own number,
status, totals and seller — all tied by a `checkout_group_uuid` the customer sees as one
purchase. Each seller sees and manages only their own order.

Rationale: each seller fulfils, ships and is paid independently; a single cross-seller order
would entangle fulfilment and payout across parties who share nothing. This is the
Trendyol/Amazon model.

Cost: there is no single "order total the customer paid" — a purchase is N orders that must
be grouped for the customer and reconciled by a future Payment against one charge. Accepted
as the marketplace's nature.

Full specification: [docs/modules/Order.md](modules/Order.md) §0.3.

---

# ADR-053 Order Lines Are Immutable Snapshots

An `OrderLine` snapshots the offer's unit price, the product title, the variant label and
the tax rate at placement. The catalog, the offer and its price may change afterward; the
order records what was bought, at what price, at what tax — permanently. Address snapshots
(ADR-056) follow the same rule.

Rationale: an order is a financial/legal record; it must not mutate when an upstream price
or name changes, or every historical total and invoice becomes unreproducible.

Cost: Order duplicates data that lives authoritatively in Catalog/Offer, and a later
correction upstream will not reflect on past orders. Accepted — that immutability is the
point.

Full specification: [docs/modules/Order.md](modules/Order.md) §0.4.

---

# ADR-054 Checkout Reserves Stock; Placement Commits (Two-Step, via Inventory)

Order is the **first real caller of `InventoryReservationContract`** (ADR-049). Checkout
**reserves** each line's stock (keyed on the order uuid); placing the order **commits** it;
a cancelled/expired checkout **releases** it. Until **Payment** exists, placement commits
directly; when Payment ships, the commit moves to "payment succeeded" and placement only
holds — the reservation window Inventory was built for.

Rationale: the two-step shape is exactly what lets Payment slot in without reshaping the
flow, and it exercises the reservation machinery Inventory shipped for this caller.

Cost: stock leaves on placement with no money taken (no Payment yet), so a placed-but-unpaid
order consumes stock until cancelled/expired; mitigated by a reservation-expiry sweep
(30-minute default). Accepted over leaving every order's stock in limbo awaiting a module
that does not exist.

Full specification: [docs/modules/Order.md](modules/Order.md) §0.5.

---

# ADR-055 Order Computes Tax from the Product's Bracket, Never Commission

Each order line carries the **KDV** extracted from its (tax-included, ADR-042) price using
the **product's tax rate** (ADR-056's Catalog addition). Order produces the tax breakdown
for the eventual invoice. It does **not** compute commission or payout — those are
Payment/Finance, applied to the order later.

Rationale: a total with no tax breakdown is not a real order line; commission has no source
of truth yet (ADR-042 §0.2).

Cost: tax logic (inclusive-KDV extraction, rounding) lives in Order before an invoicing
module consumes it, and "price is KDV-included" (Offer's decision) is committed
platform-wide. Accepted.

Full specification: [docs/modules/Order.md](modules/Order.md) §0.6.

---

# ADR-056 Customer Address Book; Separate Shipping & Billing; Product Gains a Tax Bracket

Order owns a **`CustomerAddress`** book — a customer keeps many addresses (shipping/billing
defaults). At checkout the customer picks a **shipping** and a **billing** address, which
**may differ**, and the order **snapshots both** (ADR-053). Authenticated customers only; no
guest checkout in v1.

**Catalog addition (Catalog not frozen, driven by Order):** a managed **`tax_rates`** lookup
(admin-configured KDV brackets — the lookup-table the docs always intended, `is_active`) is
added to Catalog, and the **Product gains a `tax_rate_id`** chosen at authoring and moderated
with the rest. A tax bracket is a **classification of the product**, not a commercial term,
so it does not breach ADR-037's "a product has no price or stock." Order reads the rate via
`CatalogQueryContract` and freezes it onto the line.

Rationale: real invoices need a real billing address and a real KDV rate; faking either
makes the order legally useless. Tax belongs to the product (intrinsic: a book is %1,
electronics %20), set once on the shared product, not per seller.

Cost: the address book and the tax lookup widen the Order sprint beyond the order aggregate,
and Catalog gains a field + a table for Order's sake. Accepted.

Full specification: [docs/modules/Order.md](modules/Order.md) §0.7.

## Amendment (2026-08-03) — an optional `neighborhood`, and a geo reference dataset

The customer address gains an **optional `neighborhood`** (mahalle) field — nullable, free
text in storage, exactly like `city` and `district`. Addresses remain country-agnostic:
`neighborhood` is null for non-TR addresses and is never required by the API. It is
snapshotted onto the order with the rest of the address (ADR-053), so a placed order keeps
the mahalle it was shipped to.

A separate, operator-manageable **geo reference dataset** (provinces → districts →
neighbourhoods) is added as **Localization** lookup tables — `geo_provinces`,
`geo_districts`, `geo_neighborhoods`, each with `is_active` (ADR-015) — to let TR clients
offer a cascade. It is **reference data, not part of the address aggregate**, and imposes
**no validation** on stored addresses: a client MAY send any string, and the address holds
**no foreign key** into these tables.

That last point is the amendment's load-bearing sentence. A neighbourhood is renamed, merged
or created by administrative act several times a year; an address saved before that must not
become invalid — or, worse, unreadable — because the registry moved on. The same reasoning
that keeps `city` a string keeps this one, and it is also what lets every other country keep
sending free text.

**Rationale:** usability for the TR market (the platform's launch market) without re-opening
structured world-address validation. Mahalle is what a Turkish courier actually routes on,
and ~73,000 rows is why the level could not simply be bundled into the client the way il and
ilçe were.

**Cost:** a TR-specific dataset to seed and keep current, and a read surface to serve it.
The dataset is committed gzipped rather than fetched at seed time, so refreshing it is a
deliberate act with a reviewable diff instead of a deploy that depends on a third-party host.

---

# ADR-057 Placement Holds the Reservation; Cancellation Is Actor-Typed (Amends ADR-054)

**Amends ADR-054.** Order's first build committed stock **at placement**, which left a real
gap: a placed order that is then cancelled cannot return its stock — Inventory has no
un-commit, and `release()` on a committed reference is a no-op. This ADR resolves it.

**Placement no longer commits.** Checkout **reserves**; placement moves the order to
`AwaitingPayment` and **keeps the reservation held** (`available` stays reduced). **Commit
is deferred to Payment** — when Payment ships, a successful charge commits (the units
truly leave); until then nothing commits, and a placed-but-unpaid order simply holds its
reservation. The 30-minute expiry sweep releases only **un-placed** (`Pending`) checkouts;
a placed order is held until it is paid or cancelled.

**Cancellation is typed by who cancels and why:**

| Cancelled by | Meaning | Stock |
|---|---|---|
| **Buyer** | changed their mind | **release** — returns to available |
| **Seller** | cannot fulfil (has none) | **release** + **zero the seller's on-hand** for that variant, after warning the seller. Anti-oversell: a seller who cannot fulfil clearly has no stock, so sales stop until they re-declare |
| **Admin** | oversight / dispute | **release** by default; may additionally zero for a seller-fault case |
| **System / expiry** | abandoned unpaid checkout | **release** |

The seller-zero happens at the **source of truth**: Order emits `OrderCancelledBySeller`,
which the **Offer** (not frozen) consumes **by class-string** and sets that offer's stock to
0 — flowing through the existing Offer→Inventory mirror (ADR-048), so the seller's form and
Inventory's on-hand agree. Order still imports nothing.

Rationale: deferring commit to Payment is the model the two-step reservation was built for
(ADR-049/054), and it makes "return the stock" a plain `release` in every case; the only
special case (seller-zero) is an intent about the seller's real stock, expressed at the
Offer where that stock is declared.

Cost: on-hand does not decrement until Payment exists, so a placed-unpaid order holds its
reservation indefinitely (until cancelled) — accepted, because a unit is not sold until it
is paid, and reserved stock already shows as unavailable. Post-payment cancellation
(returns/RMA, which *does* need an Inventory restock primitive) is out of scope until the
Returns sprint.

Full specification: [docs/modules/Order.md](modules/Order.md) §0.5, §3.3.

---

# ADR-058 The Customer Storefront Is a Separate Next.js App on the Same Origin; the Buyer Read Is Composed

**The customer storefront is a separate Next.js application** (owner decision, 2026-07-31),
not a Blade/Livewire surface in the monolith. It lives in a **`storefront/` folder in this
repo** (monorepo — one git flow for the build loop) and is served on the **same origin** as
the API: the storefront at the root, the Laravel API under `/api`, nginx routing between
them. Same origin means **Sanctum SPA cookie auth** works with no CORS and no token storage.

**The buyer-facing read is composed, owned by no single module** (the ADR-036 principle
applied to the marketplace-wide surface): **Catalog owns product CONTENT** (public detail +
browse/search over its index, no price/stock — ADR-037 holds); **Offer owns price +
sellability** (the buy box + a batch price/availability read); and the marketplace listing
shows **only sellable products** (≥1 active in-stock offer), Catalog's browse filtering
through `OfferQueryContract`. Customer auth/cart/address/order APIs already exist (Order).

Rationale: the owner chose the decoupled frontend; letting Catalog carry price to make the
listing a single read would breach ADR-037, so the composition is the correct cost.

Cost: a second runtime (a Node process under systemd + `next build` on deploy) on a
bare-metal box that does not yet run a queue worker; and every listing item is a
content+price composition (two reads). Accepted as the price of a decoupled, SEO-capable
storefront. Checkout stops at **awaiting payment** (ADR-054/057) — no payment UI until the
Payment sprint.

Full specification: [docs/modules/Storefront.md](modules/Storefront.md).

---

# ADR-059 Flat Human-Readable Slugs Are the Public Storefront Address; a Global Slug Registry Guarantees Uniqueness and Redirects

**Decision.** The storefront addresses product, category and brand by a **flat, root-level
slug** (`/bioderma`, `/cilt-bakimi`, `/avene-cicalfate-hassas-ciltler-icin-krem-40-ml`) — no
type prefix. Because all three, plus the storefront's own pages, share one root namespace, a
single **slug registry** (`slugs`) owns every public slug and enforces uniqueness across the
three kinds and a reserved-word list. A slug is generated from the name (Turkish-aware:
İ/ı/ş/ğ/ü/ö/ç → i/i/s/g/u/o/c), made unique with a numeric suffix on collision, and is
**stable once issued** — renaming an entity does not move its live slug. When a slug must
change, the old row is **retained as a non-canonical alias** so the resolver can report the
new address and the storefront can **301**.

**A resolver turns a slug into a type.** `GET /api/v1/resolve/{slug}` returns the entity kind
+ uuid + canonical slug (or 404), so the storefront's one catch-all route renders the right
page without guessing.

**#7 intact.** A slug is a *public* identifier like the uuid, never the internal
auto-increment id. The API keeps uuids everywhere; slugs are an **additional** public key,
and every slug-addressed endpoint accepts a uuid too.

**Every lookup resolves BY SHAPE and 404s on a miss — never a uuid-cast 500.** A value that
is not uuid-shaped must never reach a `uuid` column. `where('uuid', 'Dermokozmetik')` on
PostgreSQL is `SQLSTATE[22P02]`, an unhandled 500, while on SQLite the column is text and the
comparison quietly returns false — so the suite cannot see it. **This platform has shipped
that exact bug three times** (ADR-049's reservation reference, the ADR-056 geo cascade, and
this sprint's `?category=` filter and product page), which is why the rule is an ADR clause
rather than a code comment. `App\Shared\Support\PublicKey` is the one place that decides,
and `tests/Integration` — the PostgreSQL suite the first occurrence produced — carries a case
for every read that takes user text near a uuid column.

Rationale: the owner chose the flat, competitor-style scheme. For **ranking** a prefix is
neutral — Google ignores it — so this is an aesthetic and brand decision, not an SEO one.

Cost: a `slugs` registry table, a reserved-word guard and a backfill migration; slug-stability
logic and an alias trail that only ever grows; a resolver endpoint on every page load; and two
new public read surfaces (`/categories`, `/brands`). The shared namespace means **a new
storefront route must be added to the backend's reserved list BEFORE the frontend ships it**,
or a product may already be sitting on that address — and the symptom is not an error, it is
a product that silently cannot be reached.

Full specification: [docs/modules/Catalog.md](modules/Catalog.md) §2.7.

---

# ADR-060 Payment: Single-Merchant Settlement with Manual Payout; PayTR Behind a Gateway Port; One Payment per Checkout Group; Stock Commits on Payment Success

**Decision.** The platform is a **single merchant** at the PSP: every buyer payment
lands in the platform's own account, sellers are **not** submerchants, and the platform
records internally what each seller is owed (ADR-062) and pays them out **manually / in
batches**. The PSP splits nothing.

**PayTR is the first — and, behind a port, only visible — gateway.** All PSP contact
goes through Core's **`PaymentGatewayContract`** (`initiate` / `verifyCallback` /
`refund`); `PayTrGateway` (Infrastructure) is the sole implementation, integrated in the
**iFrame** shape so **no card data ever touches the platform** — the card and its 3-D
Secure step happen inside PayTR's iframe. A second PSP is a second adapter, never a
change to the domain.

**One Payment per checkout group.** The buyer pays once for the whole basket, so the
Payment aggregate is keyed to Order's `checkout_group` (the mirror of ADR-052's split:
Order split the basket to ship it, Payment rejoins it to charge it). `merchant_oid =
payment.uuid` — **hyphen-free on the wire** (amended 2026-08-05: PayTR refuses anything
non-alphanumeric, and the 32 hex digits are the same uuid, so the "one identifier, ours"
decision survives without a second column; the adapter strips on the way out and restores
on the way in). The amount is Σ the group's orders' `grand_total`, in **integer kuruş** —
PayTR's unit is the platform's.

**The server-to-server callback is the source of truth, not the browser redirect**, and
it is **hash-verified and idempotent**: PayTR retries until it receives `"OK"`, so the
same `merchant_oid` may arrive repeatedly and must be processed exactly once; a spoofed
or replayed callback changes nothing. On a verified success the Payment becomes `paid`
and every order in the group is confirmed; on failure/expiry the reservations are
released.

**This closes ADR-054/057.** ADR-057 left placement only **holding** the reservation and
named Payment as the committer. On the success callback, Payment drives Inventory's
reservation **commit** through the Core command port Order already uses — turning the
hold into a permanent decrement — keyed by Order's `order_uuid:variant_uuid` string
reference (ADR-049's key, never a uuid). Payment never touches a stock number itself.

Rationale: the owner chose the simpler collection path over the licensing-cleaner
submerchant model for the early phase, and to own the balance/payout ledger in-house.

Cost, stated plainly: **the platform holds money that belongs to sellers until payout.**
At scale that is the activity of a payment/e-money institution and draws BDDK licensing
obligations in Turkey. This is an accepted early-phase trade; migration to a submerchant
settlement is a future ADR, and the ledger (ADR-062) is shaped so that migration changes
who moves the money, not how the platform accounts for it. Payment imports **no** module
— Core contracts, class-string events and the gateway port only; `LayeringTest` enforces
it, as for Offer/Inventory/Order.

Full specification: [docs/modules/Payment.md](modules/Payment.md) §2–5, §10.

---

# ADR-061 Commission Is a Multi-Dimensional Rule Engine on the KDV-Inclusive Sale Amount, Frozen at Payment

**Decision.** Commission is **not** a single platform rate. The operator sets different
commissions by **product, category, brand and seller** — any of them, in any combination
— through a `commission_rules` **table** (an operator adds a rate without a release, so a
table, not an enum; ADR-015 `is_active`). Each rule optionally scopes by
`seller_org_uuid`, `product_uuid`, `brand_uuid`, `category_uuid` (any null = wildcard),
plus a `DECIMAL` `rate` and an integer `priority`. A rule with all four scopes null is
the **platform default**.

**Resolution is most-specific-wins.** For an order line (which knows its product, brand,
category and seller): take every active rule whose non-null scopes all match, rank by
**specificity** (how many scopes are set), break ties by explicit **`priority`** then
recency, and fall back to the default. So "seller X + category Kozmetik = 12%" beats
"brand Bioderma = 10%" beats "category Kozmetik = 15%" beats "default = 18%" for a line
matching all of them.

**The base is the KDV-INCLUSIVE sale amount** (owner choice 2026-08-04) — `rate ×` the
line's gross, KDV-inclusive total, in integer kuruş. Not ex-KDV. (The KDV on the
commission itself and the commission invoice the platform owes the seller are
e-fatura/accounting concerns outside this software.)

**Frozen at payment.** The resolved rate and the computed commission kuruş are
**snapshotted onto the order's lines at payment time**, exactly as Order freezes
price/tax (ADR-053). Changing a rule re-prices the **next** sale, never a settled one — a
commission a seller has already seen deducted must never move.

Rationale: real marketplaces price commission by category with per-seller and
per-product exceptions; a single flat rate could not express the owner's pricing.

Cost: a resolution step on every paid line, and a rule table whose specificity ordering
an operator must understand to predict the effective rate; `DECIMAL` rate, integer kuruş
result — never a float (ADR-005).

Full specification: [docs/modules/Payment.md](modules/Payment.md) §6.

---

# ADR-062 The Seller Balance Is an Append-Only Ledger; Payout Is Manual and Recorded

**Decision.** What a seller is owed is a **ledger**, not a mutable balance column — the
same append-only discipline as Audit and the Inventory movement ledger.
`seller_ledger_entries` (the model refuses update and delete) records typed, signed,
integer-kuruş entries — `sale_credit`, `commission_debit`, `payout_debit`,
`refund_debit`, `refund_commission_credit`, `payout_reversal_credit` — each pointing at
the order/payment/payout that produced it. **Balance is the sum of the entries, computed
on read**, never stored. (`payout_reversal_credit` ratified 2026-08-04 from the P4 build:
a payout debits at creation to reserve the funds; a bank transfer the platform later
records as **failed** must restore the balance without deleting the payout record — so
the reversal is another row, not an erasure.)

On a paid order, per seller: a `sale_credit` of the order's KDV-inclusive total and a
`commission_debit` of the commission — so the balance rises by **net of commission**.

**Payout is manual and only recorded.** An admin creates a Payout for a seller up to
their available balance (appending a `payout_debit`); it carries the external reference
of the bank transfer a human/bank actually made. The **software does not move money**
(single-merchant model, ADR-060). A payout cannot exceed the computed balance, and
concurrent payouts are guarded so a balance cannot go negative.

**Refund reverses through the ledger too.** A successful PSP refund appends `refund_debit`
+ `refund_commission_credit` (giving back the commission the platform took) and restocks
through Inventory (the mirror of ADR-060's commit). Because balance is a **sum**, a refund
after a payout simply drives the balance negative and blocks the next payout until it is
made whole — the money is never lost track of, which a mutable column could not promise.

Rationale: money owed to third parties is exactly the case where append-only,
recompute-on-read pays for itself; the refund-after-payout hazard is otherwise a silent
corruption.

Cost: balance is an aggregate query, not a column read (indexed by seller); every money
movement is one more immutable row; the platform must reconcile the ledger against the
PSP's own settlement report out of band.

Full specification: [docs/modules/Payment.md](modules/Payment.md) §7–8.

---

END OF FILE