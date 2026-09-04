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

**Amended by ADR-075** — the bulk import may open a category **it created** when a
row places a product there; a category a **human** left `accepts_products = false`
still refuses. The invariant "import respects the Category Manager's decision" is
unchanged; ADR-075 only distinguishes a human's decision from the import's own
seconds-old default.

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

**Amendment (2026-08-06, Shipping S4): `restock` takes a QUANTITY.** P5's refund was
whole-order, so its restock was whole-reservation and its idempotence could be a status
check — "has this already been restocked?". ADR-064's return window made a refund
LINE-LEVEL: a buyer sends back one of the two they bought, and the question becomes "how
many of these units are still out there?".

`restock($reference, ?int $quantity = null)`. **Null still means all of it**, so no P5
caller changed. Idempotence moved from the status to arithmetic against a new
`stock_reservations.restocked_quantity`, and the reservation stays `committed` until the
last unit is home — a partly returned sale is still partly a sale. **Asking for more than
is still out there returns what is left, never more**: an inflated restock invents stock
that does not physically exist, and the seller oversells it to somebody.

Cost: the reservation's terminal state is no longer reachable in one write, so "was this
restocked?" is two facts (a status and a count) where it was one — and a caller reading
only the status of a partly returned reservation gets `committed`, which is true but
incomplete. Accepted: the alternative is a reservation per unit, which would multiply the
ledger by every quantity anyone ever buys.

Full specification: [docs/modules/Inventory.md](modules/Inventory.md) §0.4,
[docs/modules/Payment.md](modules/Payment.md) §8.

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

# ADR-063 Shipping Is Seller-Fulfilled with Manual Tracking; v1 Charges No Shipping Fee; One Shipment per Order

**Decision.** Each paid order becomes **one shipment**, fulfilled by its **seller**: the
seller marks it shipped by choosing a **cargo company** (a `cargo_companies` lookup table
— operator-managed, `is_active`, with a tracking-URL template) and entering a **tracking
number**. There is no cargo-carrier API in v1 — tracking is manual, and any future carrier
integration sits behind a **provider-agnostic `ShipmentTrackingContract`** that has no
implementation yet. A checkout group's N seller orders (ADR-052) become N shipments;
**multi-shipment / partial shipment is deliberately absent**.

**v1 charges no shipping fee.** The storefront's "free over 200 TL" is the whole policy, so
Shipping writes **no price, no KDV, no commission** — the minor-units rule does not apply
to it. A priced shipping flow re-opens Order's frozen totals (ADR-053) and the commission
base (ADR-061) and is a future ADR.

**Shipping imports no module** — Core contracts + class-string events + the (empty) tracking
port only; `LayeringTest` enforces it, as for Offer/Inventory/Order/Payment.

Rationale: seller-fulfilled manual tracking is the fastest path to the one thing the
platform actually needs from fulfilment — a **delivery date** — without taking on carrier
integrations or re-opening the money flow. It is the standard early-phase Turkish
marketplace model.

Cost: the delivery signal is manual/inferred until a carrier API exists (ADR-064 addresses
the honesty of that); the seller enters tracking by hand; free shipping is a policy the
business may outgrow, at which point the priced flow is a real project.

Full specification: [docs/modules/Shipping.md](modules/Shipping.md) §1–2, §5–6.

---

# ADR-064 Delivery Is Inferred, Never Asserted by the Seller; ShipmentDelivered Drives Payout and the Return Window

**Decision.** The **seller cannot mark a shipment delivered** — they have an incentive to
claim it early, because payout waits on it. Delivery is inferred, whichever comes first:
the **buyer confirms** ("Teslim aldım"), or a **transit period elapses** (`shipped_at +
transit_days`, a `settings()` value, swept by a scheduled job). The resulting
`delivered_at` emits **`ShipmentDelivered`**. When a real carrier integration lands behind
the tracking port, its actual delivery event **replaces the heuristic** without changing
anything downstream, which keys off `delivered_at` however it was set.

**`ShipmentDelivered` starts two clocks in Payment** (which subscribes by class-string —
no import either way):

1. **Auto-payout** at `delivered_at + payout_hold_days` — the automatic payout ADR-060
   deferred. The admin's manual payout stays. A seller is **not paid before the buyer can
   no longer return the goods**, which is the whole reason payout keys off delivery, not
   payment.
2. **The return / refund window** — within `delivered_at + return_days` the buyer may
   request a return, which opens **Payment's customer refund** (P5 left it admin-only for
   want of a fulfilment state) and the **line-level partial refund** (refund a quantity of
   an order line: proportional commission + KDV reversal, PayTR partial refund, Inventory
   restock).

Both are **Payment enhancements driven by Shipping's event** — Payment is not frozen; they
extend it without either module naming the other.

Rationale: the delivery date is the honest basis for both paying the seller and closing the
buyer's return right; letting the party who benefits (the seller) set it would corrupt
both. Inference is good enough for v1 and is a clean seam for a later carrier feed.

Cost: a transit-period heuristic can be wrong for a slow or fast parcel until a carrier
feed exists; the windows are `settings()` the operator must tune; and Payment grows a
delivery-driven payout scheduler and a partial-refund path (the finer-grained money
arithmetic ADR-062's ledger was shaped to absorb).

Full specification: [docs/modules/Shipping.md](modules/Shipping.md) §3–4, §8 (S3–S4).

---

# ADR-065 Pre-Shipment Cancellation: Seller Line-Level Cancel + Buyer Cancel-Request (Seller-Approved), Both Refunding Through the Return Machinery

**Decision.** While a shipment is still `pending` (not yet shipped), a paid order can be
cancelled two ways — and both reuse the **line-level refund** ADR-064/S4 built
(proportional commission + KDV reversal, PayTR partial refund, Inventory restock):

1. **Seller line-level cancel** — immediate, no approval. A seller who cannot fulfil part
   of an order cancels a **quantity of a line**; that quantity is refunded to the buyer and
   restocked. Cancelling every line's every unit cancels the order and its shipment.
2. **Buyer cancel-request** — approval-gated. The buyer requests cancellation of the whole
   unshipped order (with a reason); the seller **approves** (→ full refund + restock + order
   cancelled) or **rejects** (→ the order proceeds). The buyer **cannot** cancel a paid
   order unilaterally — the seller may already be preparing it — so it is a `pending`
   **request**, not an act.

**The gate is the shipment state, not the return window.** Once a shipment is `shipped`,
cancellation is gone and ADR-064's post-delivery **return** takes over. Pre-shipment there
is no return window: a paid-but-unshipped order is always cancellable (seller) or
request-cancellable (buyer). This is the mirror of the return — the same refund, on the
other side of "shipped".

**Reuse, not reinvention.** The refund is `RefundLinesAction` unchanged — same kuruş
arithmetic, same restock, same ledger reversal. The only new things are the **triggers**
(seller cancel; buyer request + seller approve) and the **shipment-`pending` gate**.

**Placement.** The refund-triggering actions live in Payment (where the refund is), reading
the order's lines and its shipment state through Core contracts, exactly as
`RequestReturnAction` does; the `CancellationRequest` aggregate + the seller's approval
inbox sit with the order lifecycle. The server resolves the module boundary under
`LayeringTest` (and reports it, ADR-018) — no module imports another.

**No seller penalty yet.** A cancellation-rate penalty on the seller is a future ADR; v1
just refunds and restocks.

Rationale: the platform could reverse a sale **after** delivery (return) but not **before**
shipment (cancel) — the near half of the lifecycle. The seller can shed a line it cannot
fill; the buyer can back out before it ships, with the seller's consent so a half-prepared
order is not yanked out from under them.

Cost: two more refund triggers and a request/approval workflow, each reading the shipment
state; the buyer's cancel is a **request**, not instant, so the storefront must say so
("satıcı onayında") rather than confirm a cancellation that has not happened yet.

**Amendment (2026-08-06, C1 as built): the module boundary this ADR left open, resolved.**
It said the server would decide where the cancel trigger sits and report it (ADR-018). The
trigger action is in **Payment** as specified — but the SELLER'S SCREEN is Order's, and
Order imports no module, so something had to sit between the button and the refund.

**It is a Core COMMAND port, `OrderCancellationContract` — the platform's second**, after
Inventory's reservations. An event could not carry it: the seller has to be told, in the
same request, that they asked for three of two or that the parcel already shipped, and an
event announces a fact rather than asking a question. It also carries a READ
(`cancellableQuantities`) because the form's per-line caps are a subtraction only Payment
can do — the order knows what was bought and Payment knows what has already gone back.

**The gate reads through a second new Core port, `ShipmentQueryContract`** — Shipping's
first downstream read. **A missing shipment REFUSES rather than assuming "not shipped
yet"**: that assumption refunds a parcel that may already be with a carrier, which is the
one mistake here nothing later can undo.

**`PaymentRefunded` gained a `cause` (`return` | `cancellation`) rather than a second
event.** The money is identical at both ends of the lifecycle and the meaning is not: a
cancelled order becomes `cancelled` with a `cancelled` parcel, a returned one `refunded`
with a `returned` parcel. Two events would have put two listeners in Order racing to set
different terminal states on one order, decided by registration order.

**And it created a hazard worth naming.** Opening `OrderStatus: Paid → Cancelled` — needed
so a cancellation can name its outcome honestly — made `CancelOrderAction` reachable on a
PAID order, where it would have released an already-committed hold, zeroed the seller's
declared stock (ADR-057) and left the buyer's money untouched. **The transition table
stopped being the only gate**: that action and `OrderPolicy::cancel()` now refuse on
`OrderStatus::isCancellableWithoutRefund()`. A paid order is cancelled by refunding it, or
not at all.

**Amendment (2026-08-06, C2 as built): the aggregate is ORDER'S, and it holds no money.**
This ADR left the placement to the server (ADR-018) and it went where the spec's own wording
pointed — "with the order lifecycle". `CancellationRequest` records that somebody asked and
what came of it; it has no amount, no quantity and no line, because the refund, the
commission reversal and the restock all happen behind C1's `OrderCancellationContract`, in
the module that owns them. An approved request is NOT where the cancellation lives: the
ORDER is cancelled, by `PaymentRefunded`'s cause like every other cancellation on the
platform, and this row only records the seller's yes.

**"One open request per order" is `pending`-only.** A rejected request must not block asking
again — circumstances change, and a seller who said no on Monday may say yes on Thursday
while the item still has not shipped. Held by a partial UNIQUE index on PostgreSQL and by
the action everywhere, the same two-sided arrangement `customer_addresses`' default-address
indexes use, and for the same stated reason: the suite runs on SQLite.

**The gate is re-asked at approval, not only when the buyer asked.** A request can sit for
days and the parcel may leave meanwhile; the port answers "nothing cancellable" and the
approval refuses out of the same method the buyer's own attempt would have hit.

**The refund goes first and the row is stamped after** — the only ordering that fails safely.
The reverse leaves an `approved` request beside money that never moved if the PSP refuses;
this way a failure leaves a `pending` request beside a cancelled order, which is visibly odd
and already correct about the money.

Full specification: amends Order's ADR-057 cancellation and extends Payment §8 / Shipping;
recorded in those module docs as the build lands.

---

# ADR-066 A Review Is About the Shared Product and Carries the Seller It Was Bought From as an Authoritative Tag

**Status:** Accepted (2026-08-06). Spec: `docs/modules/Reviews.md`.

The catalogue is shared — one product, many sellers (ADR-037) — so a customer review had
two honest homes: the product, or the seller. **It is the product's**, and it carries the
seller it was purchased from as a **tag copied from the order**, never chosen by the buyer.

A shopper reading a product page wants "what did people who bought this think", across every
merchant who sells it; splitting reviews per seller would fragment one product's reputation
into N thin, mostly-empty lists and answer a question nobody asked. So the rating average is
the **product's**, computed over every published review, and "bu satıcıdan alanlar ne demiş"
is a **filter** on that one set — the seller filter the owner asked for — not a separate set.

**The tag is authoritative because it comes from the delivered order line, not a form
field.** A buyer cannot attribute their review to a merchant they did not buy from, and a
merchant cannot be praised or blamed for a sale that was never theirs. That is only possible
because the review binds to a real purchase (ADR-067).

**Cost:** a standalone seller/store score — "this merchant ships fast, packs well" — is a
different thing this does not provide, and some buyers will want it. It is a **future
module**; conflating it with product reviews now would put two rating systems in one table.
Questions ("Satıcıya Sor") are likewise a **separate module** (owner decision), built after
Reviews, sharing its patterns and none of its tables.

# ADR-067 A Review Binds to One Delivered Order Line; the Gate Is Delivery, and a Repeat Purchase Earns a Repeat Review

**Status:** Accepted (2026-08-06). Spec: `docs/modules/Reviews.md` §3, §5.

Only a buyer **delivered** a product may review it, and the review binds to the specific
**delivered order line** — `order_line_uuid` UNIQUE.

**Delivery, not payment.** A review promises "I used it" honesty; a paid-but-unshipped order
has no experience to report. Delivery already exists as `OrderStatus::Delivered`, inferred
rather than seller-asserted (ADR-064), so the gate rides a state the platform already trusts.

**Uniqueness on the LINE, not on `(customer, product)`.** A buyer who bought the same product
in two separate orders lived two purchase experiences and may write two reviews — the owner's
choice, and the Trendyol model — while a second review of the *same* line is still refused.
`(customer, product)` uniqueness would have forbidden the legitimate second review to prevent
the illegitimate one; line-uniqueness draws the boundary where it belongs.

**Reviews imports no module, so it cannot read `order_lines`.** `OrderQueryContract` gains
`deliveredPurchaseLines(customerId, productUuid)` returning the delivered lines (with seller
tag, variant and `delivered_at`), and Reviews subtracts the lines it has already reviewed.
The method returns lines rather than a boolean precisely because the aggregate is per-line: the
"Değerlendir" screen must show *which* purchase, and the seller tag it stamps comes from here.
Recorded in the `001_Architecture.md` amendment log as a read a later module required — the
same footing as the reads Offer and the store-page work added to `StoreQueryContract`.

**Cost:** the seller tag, variant and purchase date are **snapshotted** at creation, so a
later store rename or re-pricing never rewrites a past review — the order-line discipline
(ADR-053) applied one module further out. A review window (only review within N days of
delivery) is not enforced in v1; `delivered_at` is carried so it becomes a `settings()` tweak,
not a migration.

# ADR-068 Reviews Are Pre-Moderated: Published Only After Approval by Admin or Editor, Never the Seller; Photos Moderate With the Review

**Status:** Accepted (2026-08-06). Spec: `docs/modules/Reviews.md` §4, §6.

A review appears **only after it is approved**, and the approver is **never the seller**
(owner's hard requirement). So this is pre-moderation — the opposite of Offers' publish-then-
reactively-suspend (ADR-044) — because a defamatory or fraudulent review does its damage the
instant it is visible, and a seller moderating reviews of their own product is the fox guarding
the henhouse.

It reuses Catalog's product-moderation pattern verbatim: **status on the entity**
(`ReviewStatus: PendingReview → Published | Rejected`), a **read-and-decide Filament queue**
(`ReviewModerationResource`, the shape of `ProductModerationResource`), verdict actions
emitting events. **No `NeedsRevision`** — a buyer does not iterate on a review the way a seller
iterates on a listing; it publishes or it does not.

**The moderators are Admin + Editor**, via a dedicated `review.moderate` ability (Super Admin
bypasses already). Editor is the platform's content role, and review text and photos are
content. The seller has no lever — not approve, not hide, not reject.

**Photos moderate as PART of the review, not separately.** The Media service validates only
type and size and has no approve/reject flow (Media findings); a review with its photos is held
as one unit in `PendingReview` and published or rejected whole. Per-photo moderation is a real
feature and explicitly **not v1** — the one place the design does less than it could, stated so
it is a decision.

**Cost:** pre-moderation puts a **human in the path of every review**, so reviews appear with a
lag and the queue is real operational work — the price of never showing an unvetted stranger's
words and pictures on a public product page. The reject `reason` is kept for the internal record
but not shown to the buyer in v1.

# ADR-069 Reviews Compose the Product Page Through Dedicated Public Endpoints; the Rating Summary Is Computed on Read

**Status:** Accepted (2026-08-06). Spec: `docs/modules/Reviews.md` §7.

The public **product page has no server-side assembler** — the Next.js storefront composes it
from separate endpoints (content, offers, prices), which is the composition ADR-058 chose, and
the `StorefrontContributorContract` seam is **store-page** level, not product-page level. So
Reviews adds **its own endpoints**, the way Offer added the buy box, rather than contributing to
a page assembler that does not exist:

- `GET /products/{idOrSlug}/reviews` → published reviews + a `summary` (average, count,
  distribution, with-images count), filterable by seller and image-only (the two filters the
  owner asked for). Slug-or-uuid resolved through `CatalogBrowseContract` before any column is
  touched — the 22P02 slug-into-uuid trap the platform keeps re-learning.
- `POST /products/ratings` → a batch `{productUuid: {average, count}}` for listing-card star
  badges, mirroring `POST /offers/prices`: one call for a whole grid, not a query per card on
  the busiest anonymous route. An unreviewed product is absent, never `0.0`.

**The average and distribution are computed on READ, not stored** — the same discipline as the
buy box (ADR-045): a stored aggregate is a second source of truth that drifts the first time a
review is deleted or a moderation reverses. If these reads ever get hot, a denormalised counter
is a later optimisation **behind the same endpoints** — the public shape does not change.

**Cost:** computing the summary per request costs a `GROUP BY` on the product's published
reviews on a hot anonymous path; accepted for v1 at expected volumes, with the denormalisation
escape hatch named above so reaching for it later is a change of implementation, not of
contract.

---

# ADR-070 Questions Are Product Q&A Directed at the Buy-Box Seller; Anyone May Ask; Moderation Is Reactive, Not Pre-Publication

**Status:** Accepted (2026-08-07). Spec: `docs/modules/Questions.md`.

"Satıcıya Sor" is a product question a shopper asks a seller and the seller answers, in
public. Three decisions define it, and each is the mirror image of a Reviews decision
(ADR-066/067/068), on purpose.

**It is about the PRODUCT and directed at ONE seller — the buy-box winner, snapshotted
by the server.** The catalogue is shared, so a question, like a review, is the product's;
but unlike a review it needs a specific merchant to answer, and the honest one is the
seller the shopper is looking at. The server reads `OfferQueryContract::featuredOfferForProduct`
and snapshots its `store_uuid` + `selling_org_uuid` at ask time. The client sends no
target and so cannot forge one; a product nobody sells has no seller to ask and the
request is refused.

**Anyone signed in may ask — there is NO purchase gate.** A review reports an experience,
so ADR-067 gates it on a delivered purchase; a question is asked *to decide whether to
buy*, so the same gate would defeat it. A signed-in customer is the only requirement —
enough to attribute the question and let the asker find the answer.

**Moderation is REACTIVE: the seller's answer publishes the pair; an admin hides after
the fact.** Reviews are pre-moderated (ADR-068) because a stranger's unvetted words and
photos do their damage the instant they are visible. A question is different: it is not
public until the *seller* chooses to answer it, and that answer is itself the seller
vouching for the exchange — so a human in front of every question would be a queue with
no payoff. An admin hides an unacceptable one (pending or answered) reactively, with a
reversible flag rather than a status, because the same lever must work at both ends of
the lifecycle and must be undoable.

**Cost:** reactive moderation means an abusive *unanswered* question is visible to the
target seller before an admin can act — accepted, because it reaches only the seller and
the admin, never the public, until answered. A standalone "report this question" surface
for shoppers is a future addition; v1's admin finds them through the queue.

# ADR-071 The Target Seller Owns the Answer; Seller-Panel Tenancy by Store; the Admin's Only Lever Is a Reversible Hide

**Status:** Accepted (2026-08-07). Spec: `docs/modules/Questions.md` §6–7.

The seller a question was aimed at is the one who answers it, and they do it from the
**seller panel** — not the platform on their behalf.

**Tenancy is per-resource query scope, not a Filament tenant** (ADR-030, as every seller
surface). The seller `QuestionResource` scopes `whereIn('store_uuid', $sellerStoreUuids)`,
resolving the seller's stores through `OrganizationAuthorizationContract::organizationIdsForUser`
→ `StoreQueryContract::liveStoresForOrganization` — the exact path Order's seller resource
walks. A seller sees only questions aimed at their own stores; the snapshotted `store_uuid`
(ADR-070) is what makes that a single `whereIn`.

**Only the target seller — and the Seller Employee role — may answer.** The seller role
gets `question.answer` from its guard automatically; the employee allow-list gains it
deliberately, because answering buyer questions is delegable staff work like product
authoring. The answer is the publish event: `AnswerQuestionAction` moves `Pending →
Answered`, stamps `answered_by`/`answered_at`, and emits `QuestionAnswered`.

**The admin does not answer — the only admin lever is a reversible hide.** A platform that
answered in a seller's place would be making a promise the seller did not make and
speaking for a merchant it does not control. So `QuestionModerationResource` (a separate
class from the seller resource, platform-wide, `question.moderate` = Admin + Editor) can
hide or un-hide, and nothing else. Hiding is a `hidden_at` flag, not a terminal status,
so it applies to a pending abusive question and an answered one alike, and can be undone.

**Cost:** a seller who never answers leaves a question pending forever, invisible to the
public — there is no SLA and no escalation in v1. That is the accepted price of letting
the seller, not the platform, own the answer; a future ADR can add a "no answer in N
days" nudge without changing this ownership.

---

# ADR-072 A Placed Order Expires After a Payment Window, Releasing Its Reservation; a Late Payment Re-Reserves or Refunds

**Status:** Accepted (2026-08-08). Amends ADR-052/054/057 (Order lifecycle + reservations).
Work order: `BUILD_ORDER_EXPIRY.md`.

An order placed but not paid holds a stock **reservation** (ADR-057: placement holds it,
Payment commits it). Until now nothing released that hold if the customer simply walked away
from the payment step — the reservation sat forever, and a seller's `available` (`on_hand −
reserved`) fell to zero while their offer still declared stock, taking their listing off the
buy box. This ADR adds the missing release.

**A placed order expires after a payment window — `settings('order.payment_window_minutes')`,
default 5 — moving `AwaitingPayment → Expired` and RELEASING its reservation.** `Expired` is a
new `OrderStatus`, distinct from `Cancelled`: a cancellation is somebody's decision, an expiry
is the clock. A minute-by-minute scheduled sweep (the money-critical scheduler pattern, as the
delivery sweep and auto-payout) finds `AwaitingPayment` orders past the window whose payment
did not succeed and expires them. This is a **different window from the pre-placement
abandonment** the existing `ExpireReservationsJob` handles (`Pending`, 30 min → `Cancelled`) —
that job was also never scheduled, a latent leak this work fixes too.

**An expired order is hidden from the customer's order list.** It is excluded from
`GET /orders` — a shopper who never paid should not see a wall of dead "ödeme bekliyor" rows.
It remains reachable by direct uuid (nothing is deleted), and an `AwaitingPayment` order still
*within* the window keeps showing in the muted "Tamamlanmayan ödemeler" section so the customer
can still complete payment.

**A payment that succeeds AFTER expiry re-reserves or refunds — never oversells, never keeps a
paid customer empty-handed.** The PayTR callback is the source of truth and can arrive late (a
slow 3-D Secure). When a verified success lands for a group whose orders have expired, the
settlement path **re-reserves every line**: if the stock is still there, the order recovers
(`Expired → Paid`, the one transition out of `Expired`) and commits normally; if any line can
no longer be reserved — someone else bought it in the meantime — the **whole payment is
refunded** and the orders stay `Expired`. This lives in `SettlePaymentCallbackAction`, which
already holds the Inventory reservation port; the Order-side listener cannot re-reserve and is
left to transition only.

**Cost, stated plainly:** a 5-minute window is shorter than PayTR's own iframe session, so a
customer slow at 3-D Secure can have their order expired mid-payment — which is exactly why the
re-reserve-or-refund path exists, and why the window is `settings()`-tunable rather than a
constant: if support sees real churn, an operator lengthens it without a release. The recovery
path is the intricate part and the one most worth its tests: the race is real money against
real stock.

---

# ADR-073 A Return Is a Request the Seller Approves and Completes, Not an Instant Refund; the Refund Fires When the Seller Has the Goods Back

**Status:** Accepted (2026-08-08). Amends ADR-064 (the return window opened an *instant*
customer refund). Work order: `BUILD_RETURNS.md`.

ADR-064 gave the customer a refund the moment they asked, within the return window — "the
window IS the approval." That is wrong for physical goods: it refunds before the seller has
the product back, and a marketplace cannot make sellers whole on trust. This ADR makes a
return a **request the seller approves, then completes**, and moves the refund to the end.

**The flow is the post-delivery mirror of ADR-065's pre-shipment cancellation request, plus a
return code.** Four states on a new `ReturnRequest` (in Order, mirroring `CancellationRequest`):

1. **Requested** — the customer names lines + quantities + a reason. **No money moves.** The
   order stays `Delivered`. Gated on the return window still being open (ADR-064's
   `SettlementWindow::isReturnOpen`, read through a Core port — Order imports no module).
2. **Approved** — the seller approves and shares an **iade kodu** (return cargo code) and a
   carrier, so the customer knows how to send it back. Or **Rejected**, with a reason (no money,
   and a rejection does not block asking again).
3. **Completed** — the seller has the parcel back and presses "İadeyi tamamla". **Only now does
   the refund fire** — the existing `RefundLinesAction` (PSP refund + Inventory restock + ledger
   reversal + `PaymentRefunded` with `cause: return`), unchanged. The order becomes `Refunded`.

**The money machine does not change — only its trigger does.** `RefundLinesAction` is
input-agnostic (it takes a `ReturnRequestDTO`); today `RequestReturnAction` fires it on the
customer's request, and this moves that firing to the seller's completion. The seller triggers
it through a **new Core command port `OrderReturnContract`** (`completeReturnBySeller` +
`returnableQuantities` + `isReturnOpen`) — the exact C1 pattern (ADR-065), because Order owns the
`ReturnRequest` and imports no module. C1's `OrderCancellationContract` **cannot** be reused: it
refuses any shipped parcel (`assertAwaitingHandover`) and hard-codes `cause: cancellation`,
whereas a return is a delivered parcel coming back with `cause: return`.

**The return code is the customer's instruction to ship it back** — free-text entered by the
seller on approval, with a carrier picked from the `cargo_companies` list (read through Shipping's
`ShipmentQueryContract`, extended with the active-carrier list, so the Order resource needs no
Shipping import). v1 has no return-shipment tracking; the code + carrier name is the whole
handoff, matching Shipping v1's manual-tracking philosophy.

**Cost, stated plainly:** the customer waits for the seller twice — once to approve, once to
complete after receiving the goods — where before they had their money instantly. That is the
correct trade for not refunding on trust, and it is the same shape buyers already accept for the
pre-shipment cancellation. A seller who never completes leaves the refund pending; v1 has no SLA
or auto-complete (a future ADR, like Questions' unanswered-question nudge). **The PayTR refund
still fires at completion**, so the sandbox-refund limitation that blocks it today must be resolved
before this is usable in production — flagged in the work order, not solved by it.

---

# ADR-074 The Catalogue Is Bulk-Loaded From an Admin Excel/CSV Import That Drives the Existing Authoring Actions; v1 Is One Default Variant per Product

**Status:** Accepted (2026-08-08). Work order: `BUILD_CATALOG_IMPORT.md`.

A real catalogue is thousands of products; hand-entering them one Filament form at a time is not
a plan. The platform gets an **admin self-service bulk import**: upload an Excel/CSV, map the
columns, and the rows become categories, brands, products, variants, tax brackets and images —
queued, with a per-row failure report.

**It DRIVES THE EXISTING AUTHORING ACTIONS, it does not write models.** Each row runs the same
chain a seller's "ürün aç" does — `DraftProductAction → UpsertVariantAction → SubmitProductForReviewAction
→ PublishProductAction` — plus category/brand/tax resolution and `addMediaFromUrl`. Writing rows
straight into the tables would bypass the moderation lifecycle, the slug registry, the GTIN unique
guard, the variant `combination_key`, and the events other modules listen to; going through the
actions means the import cannot create a product the platform's own rules would reject, and a
future rule change protects the importer for free. The import is an admin capability
(`catalog.products.moderate`), so it drafts and **publishes in one pass** — the products are the
platform owner's real catalogue, not a stranger's submission awaiting review.

**It is built on Filament's own import infrastructure** (`league/csv` + `openspout`, already
bundled with `filament/actions` — zero new dependencies), which gives queued, chunked per-row
jobs and a `failed_import_rows` table (a downloadable "these 12 rows failed and why") for free.
A row that fails — a missing category name, an unreachable image URL, a duplicate GTIN — is
recorded and skipped; it never fails the other 4,999.

**Products dedup by GTIN.** The barcode is the catalogue's natural key (already `UNIQUE` on
`products`), so re-running the same file updates rather than duplicates, and two sellers' feeds of
the same product converge on one catalogue entry (ADR-037's shared catalogue, made operable). A
row with no GTIN falls back to title+brand and is created fresh.

**v1 is ONE DEFAULT VARIANT per product, and fresh categories carry no required attributes** —
both deliberate scope cuts, so the first load of thousands of products actually lands. Colour/size
variant **axes** are attribute-schema-defined and moderated (ADR-038); auto-creating that schema
from a spreadsheet cell is where a bulk import quietly corrupts a taxonomy, so rich variants and
descriptive attributes are a **phase 2** with its own design. Because the import creates the
categories fresh, they have no required attributes, so `PublishProductAction`'s schema-conformance
gate passes — the owner's Category Manager enriches the schema afterward.

**Cost, stated plainly:** v1 imports products as single-variant, so a shirt that comes in three
sizes arrives as one product (or three rows/products) until phase 2 adds axes — a real limitation
the owner accepted to get the catalogue in. Image fetching is N HTTP downloads on a queue; a slow
or dead image host shows up as failed rows, not a hung import. And the importer is only as safe as
the authoring actions it drives — which is the point of driving them.

---

# ADR-075 The Bulk Import Opens a Category IT Created When a Row Sells There; a Human-Closed Category Still Refuses (Amends ADR-047)

**Status:** Accepted (2026-08-11). Amends ADR-047. Work order: `BUILD_CATALOG_IMPORT_FIX.md`.

A real catalogue puts products at a node that is **also a parent** — *Cilt Bakımı >
Cilt Temizleme Ürünleri > Cilt Temizleyiciler* in one row, *Cilt Bakımı > Cilt
Temizleme Ürünleri* (a product sold directly at that middle node) in another. ADR-047
already permits this shape (`accepts_products = true` **with** children). The first
real import still failed 5 rows on it, and the reason is a self-inflicted one: the
import creates each intermediate node **closed** (`accepts_products = false`, ADR-047's
default for a node it opens on the way down), then a later row that terminates at that
same node is refused by the very flag the import just set seconds earlier.

**The fix distinguishes WHO closed the category.** A node the **import created** carries
no human moderation decision — its `false` is a default, not a judgement — so when a row
terminates there (a product is sold directly at it), the import **opens it**
(`accepts_products = true`). A node a **human** left closed in the Category Manager is a
real decision; the import **still refuses the row** and reports it, exactly as ADR-047
says. To tell them apart the category records its **origin** — a boolean marker set at
creation (`created_by_import`, or equivalent) — because after the fact a closed node
created by a prior import chunk and a closed node a human curated are otherwise
indistinguishable, and re-running the file must stay idempotent.

Concretely: a node accepts products iff **any row terminates at it** OR a human opened it;
a pure intermediate that no row sells at stays closed; a human-closed node is never
flipped. ADR-047's invariant — *the import does not overrule the Category Manager* — is
preserved verbatim; ADR-075 only stops the import from overruling **itself**.

**Bundled, but a separate concern: the import job had no retry ceiling.** `ImportCsv`
shipped with `$tries` undefined (unlimited), `$backoff` undefined (0s, retry instantly),
and only a 24-hour `retryUntil` — so those 5 rejected rows, re-thrown per chunk, drove
**29,074 attempts** overnight and **~155,000** duplicate failure rows before the window
closed. Two independent hardenings: (1) a row the importer means to reject must fail at
the **row** level (recorded in `failed_import_rows`, the chunk continues) and never throw
out of the chunk job; (2) the job carries an explicit **`$tries` ceiling and a `$backoff`**
so that no future defect — this one or another — can ever again turn one bad row into tens
of thousands of retries. The retry storm was the real cost; the wrong five rows were cheap.

Cost: one boolean column on `categories` and the small asymmetry that an import-created
node is *permissive* where a hand-built one is *restrictive*. We accept it because the
alternative that needs no column — open **every** path segment — abandons ADR-047's
default and lets a seller later author a product straight into an empty container the
import happened to pass through, and the alternative that needs no code — reject and make
the operator restructure a correct 5,000-row file — punishes correct data for the
import's own default.

Full specification: [docs/modules/Catalog.md](modules/Catalog.md) §3.2 and
`BUILD_CATALOG_IMPORT_FIX.md`.

---

# ADR-076 Sellers Feed Price + Stock Through a Token-Authed Offer Sync API and a CSV Mirror; the API Is the Priority, Both Drive One Upsert Action

**Status:** Accepted (2026-08-11). Design: `docs/superpowers/specs/2026-08-11-seller-offer-feed-design.md`. Extends ADR-042/043; adds one read-only method to Core `CatalogQueryContract`.

Offers are created **one at a time** in the Filament seller panel. A real store is
thousands of SKUs whose **price and stock change daily**, so there is no workable way to
keep them current — and the catalogue import (ADR-074) deliberately loads *catalogue*,
not price or stock, so the products sit in the catalogue with no seller selling them.
This adds the missing ingress: a **seller offer feed**.

**Two doors over one brain.** A single `SyncSellerOfferAction` (upsert by seller org +
variant) holds all the logic; a **token-authed REST API** — the priority — and a
**CSV import** in the seller panel are thin adapters over it. The API serves sellers
whose systems integrate (the platform's own store feeds this way); the CSV serves
sellers who only have a spreadsheet. One brain means the two doors can never diverge.

**It feeds price + stock only; it never creates a product.** A row/item is matched to an
existing **published** catalogue product by **GTIN**; an unmatched or unpublished GTIN is
reported as a failed item and nothing is created. Auto-creating a product here would drag
the moderation lifecycle, the slug/GTIN guards and the category schema into Offer and
bulge the ADR-037/042 boundary — product creation stays the catalogue import's job. This
required **one read-only method** on Core `CatalogQueryContract`
(`publishedVariantUuidForGtin`); Catalog stays unaware of Offer, and `CatalogBoundaryTest`
stays green because a uuid string carries no price or stock back into the catalogue.

**The feed DRIVES the existing offer actions, it does not write the Offer model** — the
same rule ADR-074 set for the catalogue import. `CreateOfferAction` /
`UpdateOfferStockAction` emit the stock events **Inventory mirrors on-hand from**
(ADR-048) and the **search index** consumes; a model written directly would be an offer
that is right in the table and invisible to availability and search. So the action
composes those actions, one invocation per item, each in its own transaction — one bad
item never rolls back its neighbours.

**Shape.** REST: `POST /api/v1/seller/offers/sync` (bulk price+stock upsert, bounded to
`offer.feed.max_batch` per call, **synchronous** with a per-item result report),
`.../stock` (stock-only fast path, because stock changes far more often than price),
`.../withdraw`. CSV: a seller-panel importer on Filament's own queued/chunked import
infra with a downloadable failure report, idempotent on (seller org, GTIN). **Auth:**
per-seller **Sanctum** bearer tokens on a **dedicated `sanctum_seller` guard** (the
existing `sanctum` guard is customer-bound and 401s a seller token — corrected at build,
ADR-018), issued/revoked in the seller panel, scoped by `OfferPolicy` to the seller's
**own** org; guard isolation holds by construction — an admin/customer token cannot
resolve on the sellers-provider guard. **Stock is absolute**, **price is a
decimal string** on the wire and minor units internally (the money rule holds), and the
whole thing is **idempotent** — a re-send that changes nothing emits nothing.

**Cost, stated plainly.** A public write surface is a new attack surface and a versioning
obligation (`/api/v1`), plus a token lifecycle to manage. A synchronous bulk endpoint
must bound its batch or a large POST occupies a worker — hence the `max_batch` cap and
the CSV/queue path for the thousands. We accept it because per-form entry does not scale
to a live multi-thousand-SKU store, the shared action keeps the two doors honest, and the
GTIN-reject rule keeps price/stock and the catalogue cleanly apart.

Full specification: `docs/superpowers/specs/2026-08-11-seller-offer-feed-design.md`.

---

# ADR-077 "Also Bought" Recommendations Are Computed on Read From Paid-Order Co-Occurrence; No Stored Recommendation Model

**Status:** Accepted (2026-08-13). Work order: `BUILD_ALSO_BOUGHT.md`.

The product page wants a "Bu Ürünü Alanlar Bunları da Aldı" strip — products bought in
the same basket as the one being viewed. There is no behavioral data source today, so
this defines one.

**A public read endpoint `GET /api/v1/products/{product}/also-bought`** returns the
products that co-occur in the same **checkout group** as this product across **paid**
orders, ranked by co-occurrence frequency, filtered to currently **published + sellable**
products, shaped as the same `ProductCard[]` the browse endpoint returns (up to 12).
`{product}` resolves by uuid or slug like the other product routes.

**Computed on read, no stored recommendation table** — the same stance as the buy box
(ADR-045) and the rating average (ADR-069). The truth is the **order lines** (ADR-053,
immutable snapshots); a materialized recommendation table would be a second place to
drift, and at launch volume the live query is cheap.

**Co-purchase is the checkout GROUP, not a single order.** A basket splits into one order
per seller under a `checkout_group` (ADR-052), so "bought together" spans the group — two
products from two different sellers in one basket are a co-purchase. **Only paid orders
count**: a cart is not an order, and a cancelled/expired basket is not a purchase.

**It reads Order data, and recommendations are a read concern.** Order owns the lines; the
endpoint gets ranked co-purchased product uuids through a **new Core `OrderQueryContract`
method** (e.g. `coPurchasedProductUuids(productUuid, limit)`), and Catalog hydrates them
into published+sellable cards preserving rank — no module imports another (`LayeringTest`
holds; Catalog reaches Order only through the Core contract).

**Empty until sales, then automatic.** With no purchase history it returns `[]`, the
storefront hides the section, and it lights up on its own as orders accumulate — the
storefront is wired now (`AlsoBought` + `getAlsoBought`, degrade-to-empty) and needs no
change when the endpoint ships. This is the same family as the deferred "Çok satanlar /
en çok değerlendirilen" strips — all order/rating aggregation reads.

**Cost:** the co-occurrence query grows with order volume, so the endpoint is cached and,
when it bites, the co-occurrence is precomputed into a periodically-rebuilt table — a
documented follow-up, not v1.

Full specification: `BUILD_ALSO_BOUGHT.md`.

---

# ADR-078 "Çok Satanlar" and "En Çok Değerlendirilenler" Are Ranking Read Endpoints, Computed on Read Like ADR-077

**Status:** Accepted (2026-08-13). Extends the ADR-077 pattern. Work order: `BUILD_RANKING_STRIPS.md`.

The homepage wants two always-on ranking strips it had deferred: **"Çok Satanlar"** and
**"En Çok Değerlendirilenler"**. Both are the same shape as ADR-077's also-bought — an
aggregation read with no honest static source — so they follow the same rules.

**Two public read endpoints**, each returning the browse `ProductCard[]` shape (up to 12),
**published + sellable** only, **computed on read**, no stored ranking table:
- `GET /api/v1/products/best-sellers` — ranked by **units sold across paid orders** (a cart
  is not a sale; cancelled/expired baskets are not sales), read through a **new Core
  `OrderQueryContract` method** (e.g. `bestSellingProductUuids(limit)`), the sibling of
  ADR-077's co-purchase method.
- `GET /api/v1/products/most-reviewed` — ranked by **published (approved) review count**,
  read through a **new Core Reviews query-contract method** (e.g.
  `mostReviewedProductUuids(limit)`). Reviews owns the count; Catalog hydrates the cards.

**Catalog hydrates both** — it calls the Core method for the ranked uuids and turns them
into published+sellable cards preserving rank (`whereIn` + explicit order-by-position). No
module imports another; `LayeringTest` and `CatalogBoundaryTest` hold.

**Empty until data, then automatic** — best-sellers is `[]` until sales exist,
most-reviewed `[]` until a product is reviewed; the storefront (already wired,
`getBestSellers` / `getMostReviewed`, degrade-to-empty) hides each strip and it appears on
its own. Both cached; precompute into a rebuilt table only if volume ever bites — the same
documented follow-up as ADR-077.

**Why on read, not stored:** the truth is the order lines (ADR-053) and the reviews
(ADR-069, already computed on read); a materialized ranking would be a second place to
drift, and at launch volume the query is cheap. "En yüksek puanlı" is deliberately left out
for now (rating average is noisy at low review counts); it can join this surface later.

Full specification: `BUILD_RANKING_STRIPS.md`.

---

# ADR-080 Listing Filters Are Faceted: Price Range + Brand, Computed on Read and Returned in the Browse Meta

**Status:** Accepted (2026-08-14). Extends ADR-058 (the buyer listing). Work order: `BUILD_LISTING_FILTERS.md`.

The category, brand and search listings sort but do not filter. Two filters are added:
a **price range** and, on category/search, a **brand** facet. Both follow the same
compute-on-read stance as the rest of the buyer read surface — no stored facet tables.

**The browse endpoint gains two request params and one meta block.** Request:
`price_min` / `price_max` as **decimal strings** (TL), converted to minor units at the
boundary and applied against the buy-box (Offer) price — the same price the sort already
orders by. Response `meta.facets`:
- `brands`: the brands present in the current query (category + `q`), each with a count,
  so the UI can offer only brands that actually return results.
- `price`: `{ min, max }` decimal strings — the price span of the current query, for the
  range control's bounds.

**Facets are computed over the query MINUS the applied brand/price,** so a shopper who
picks a brand still sees the other brands to switch to, and the price bounds don't collapse
to the filtered subset. Standard faceting. Category and `q` DO scope the facets.

**`category + brand` together already works** (verified live: a category of 1,138 narrowed
to 20 by a brand); this ADR only adds the price filter and the facet DATA the UI needs to
present the choices. Price lives in Offer, so the price filter and the price facet read
through the same **Core contract** the sellable-wall uses (`OfferQueryContract`) — Catalog
imports no Offer, `CatalogBoundaryTest` stays green. The `is_sellable` denormalisation
(ADR-079) keeps these aggregations cheap.

**Filters live in the URL, not component state** (the listing's existing decision, ADR-058):
`?price_min=&price_max=&brand=` — a filtered view stays shareable, bookmarkable and
crawlable, and the pages stay server components. The parametrised variants remain
`noindex,follow` (already the rule for the search page), so facets don't bloat the index.

**Out of scope (v1):** rating and attribute (Cilt Tipi, Hacim…) facets — attribute
faceting needs the moderated category schema and is a larger, separate step; rating needs
its own facet read. Both can join `meta.facets` later without changing this shape.

Full specification: `BUILD_LISTING_FILTERS.md`.

---

# ADR-081 Loyalty Is a Standalone Module With a Compute-on-Read, Append-Only Points Ledger

**Status:** Accepted (2026-08-15). Spec: `docs/modules/Loyalty.md`. Phase 1 work
order: `BUILD_LOYALTY_P1.md`.

## Context

The platform needs customer points: earn on signup, on a published review, on a
completed purchase; later spend as a checkout discount. The question that shapes
everything is where the **balance** lives and how the module connects to the four
contexts that generate points (Identity, Reviews, Order, Payment) without violating
the layering rule that no module imports another.

## Decision

Loyalty is a **standalone module** (like Payment, Offer, Inventory, Order) that
**imports no other module** — it reads through Core contracts and subscribes to
other modules' events **by class-string** only. Its heart is an **append-only
ledger** (`loyalty_ledger`), one row per event with a signed integer `points` and a
`(source_type, source_uuid)` provenance key. The **balance is computed on read** as
the signed sum of the ledger; there is no stored `balance` column. A reversal is a
new negative (or positive) row, never an edit or a delete — the model refuses
updates and deletes exactly as Audit, Activity and the seller ledger (ADR-062) do.

## Consequences

A deleted or reversed row changes the next read with nothing to invalidate — the
same property that made the buy box (ADR-045) and the rating average (ADR-069)
compute-on-read. `LayeringTest` guards the no-import boundary in both directions.
The cost is that reading a balance sums the ledger every time; for a customer's own
points this is trivially small, and if a hot aggregate ever appears it becomes a
derived cache rebuilt from the ledger (ADR-079's rule), never a second source of
truth. A point is an **integer count**, not money — the minor-units rule (ADR-005)
does not reach the balance; only the redemption value is money-adjacent (ADR-083).

---

# ADR-082 Points Are Earned From Three Events; Purchase Points Finalize Only After the Return Window, and Only Really-Paid TL Earns

**Status:** Accepted (2026-08-15). Spec: `docs/modules/Loyalty.md` §3.

## Context

Points are earned three ways, but the marketplace has returns, cancellations and
payment expiry — so *when* a purchase grants points decides whether the platform
ever has to claw points back from a balance a customer may already have spent.

## Decision

Three listeners, each subscribed by class-string:

- **Signup** — on the customer-registration event, grant `loyalty.earn.signup`
  once per customer (idempotent on `(customer_uuid, 'signup')`).
- **Review** — on `ReviewPublished` (the moderation-approved event, NOT
  review-submitted), grant `loyalty.earn.review` once per review. Reviews are
  already one-per-delivered-line (ADR-067), so this cannot be farmed.
- **Purchase** — written only when a delivered seller-order passes its return
  window un-returned (`delivered_at + return_days`), by a **daily sweep** reading a
  Core `OrderQueryContract` addition; the amount is
  `floor(paid_tl × loyalty.earn.purchase_rate)` on the seller-order's KDV-included
  amount, **excluding any part paid with points**.

## Consequences

A returned or cancelled order **never grants points**, so nothing is ever clawed
back from a live balance — the whole reason the finalize point is the return window
and not payment. This mirrors Payment's auto-payout timing (`delivered_at +
payout_hold_days`), so the two "the sale is now real" clocks agree. **The scheduler
is part of the feature**: like Order expiry (ADR-072) and auto-payout, purchase
points are inert without cron. Excluding the redeemed TL from the earn base
(§2.3/ADR-084) stops points from regenerating themselves — otherwise a large
balance would earn on its own spending in a loop. The recorded trade-off: a review
later removed by moderation keeps its points in v1 (no clawback), because the abuse
surface is already closed by the one-per-line cap.

---

# ADR-083 Earn Rates and Point Value Are Operator Settings, Not Code; the Point Is an Integer, the Value a DECIMAL Rate

**Status:** Accepted (2026-08-15). Spec: `docs/modules/Loyalty.md` §4.

## Context

The owner must tune how generous the program is — the signup bonus, the review
bonus, the earn rate, and what a point is worth — without waiting for a release.
The enum-or-lookup test in CLAUDE.md ("an operator must reconfigure it without a
release → table/settings") puts these values squarely in configuration.

## Decision

Five keys in the platform `settings()` table, edited from **one Filament admin page
("Puan Ayarları")** gated to Admin/Finance, every write audited:
`loyalty.enabled`, `loyalty.earn.signup`, `loyalty.earn.review`,
`loyalty.earn.purchase_rate`, `loyalty.redeem.value`. Defaults give **5% back**
(earn 1 point/TL, value 0.05 TL/point). Points are earned as **integers** (floor);
`loyalty.redeem.value` is the single money-adjacent number and is a **DECIMAL rate**
(TL-per-point), treated like a tax or exchange rate (ADR-005), never an integer
minor-unit.

## Consequences

The owner reaches any effective percentage by moving two numbers, with no deploy;
`loyalty.enabled = false` halts earning and hides redemption while leaving balances
intact. Rate changes are **not retroactive** — settings are read at the moment an
event is priced and written into the ledger row, so already-earned points never
shift when the rate does. Because `settings()` returns the caller's default when the
table is unreachable (CLAUDE.md), a settings outage degrades to "no points priced"
rather than a crash in a checkout or a listener.

---

# ADR-084 Redemption Is a Platform-Funded Checkout Discount Through a Core LoyaltyContract Command Port; a Refund Re-Credits the Spent Points

**Status:** Accepted (2026-08-15). **Phase 2** — deferred behind Phase 1 (earn +
display + admin). Spec: `docs/modules/Loyalty.md` §5.

## Context

A customer spends points as a checkout discount with no cap in v1. This touches the
charged amount, the multi-seller split, and refunds — but Order/Payment must not
import Loyalty and Loyalty must not import them.

## Decision

Redemption crosses through a **Core command contract** `LoyaltyContract` — the
platform's next command port after Inventory's reservation and Order's
cancellation/return ports — with `hold(customer, points, checkoutGroup)` →
`commit(checkoutGroup)` → `release(checkoutGroup)`. A **hold** is transient (like an
Inventory reservation); only `commit`, on payment success, writes the `−points` row.
The discount is **platform-funded**: it lowers what the customer pays PayTR, and
each seller-order still settles on its **full** amount — Loyalty writes no
commission, KDV or payout figure. A refunded/returned/cancelled order **re-credits**
the spent points (a new positive row keyed to the refund) while the TL charged is
refunded through PayTR.

## Consequences

Keeping the discount platform-funded is what lets Loyalty stay out of the commission
engine and the seller ledger entirely — the alternative (splitting the discount
across seller-orders and re-deriving commission) was rejected as v1 complexity for
no seller benefit, since the platform is the party running the program. The seam is
a command, not an event, for the same reason Inventory's is: a customer pressing
"use points" at checkout must be told **in that request** that their balance changed
underneath them. Because the redeemed TL is excluded from the earn base (ADR-082), a
customer cannot pay with points and earn points on that same money. A refund leaves
the customer whole — points back, money back — with no net gain or loss, and the
purchase points for a refunded order were never written (ADR-082), so there is
nothing to reverse on the earn side.

---

END OF FILE

---

# ADR-079 The Sellable Wall Is Denormalised onto the Product, and Availability Is Read in Bulk

**Status:** Accepted (2026-08-14). Forced by a production incident. Work order:
`BUILD_BROWSE_PERF.md`. Amends the deferral recorded in `PublicProductBrowse`'s
docblock and Storefront.md §1.1.

## Context

Every browse asked `OfferQueryContract::sellableProductUuids()` — "which products
have at least one active offer, from a live store, with stock available" — and
turned the answer into a `whereIn`. On the live catalogue that was **7,025 uuids per
request**, and building it walked **9,510 active offers asking Inventory about each
one in turn**.

Measured on test.raftabul.com: single-product reads 0.39s, **every browse 22 seconds**,
and past the proxy timeout a 504 that reached shoppers as *"Application error: a
server-side exception has occurred"*. The homepage strips, the product-page rails and
`/urunler` are all browses, so the whole storefront was affected.

The work order's hypothesis was that `available` was being summed from the
append-only movement ledger on read (ADR-048/050). **It was not.** `on_hand` and
`reserved` have been columns on `stock_items` since Inventory shipped; `available` is
arithmetic on two integers. The balance was never the cost. **The count of reads
was** — one `stock_items` query per offer, inside a loop.

## Decision

**1. Availability is asked in bulk.** `InventoryQueryContract::availableKeysAmong()`
answers for a whole set in one query, keyed `sellingOrgUuid|variantUuid` because a
pool is per (org, variant) (ADR-051). The port did not move and Offer still may not
join Inventory's table; only the round trips went. Narrowed by the variants asked
for, so one product's buy box still reads one product's pools.

**2. The sellable fact is denormalised onto the product.** `products.is_sellable`,
indexed composite with `status`, is what the browse filters on — no uuid list, no
`whereIn`. It is maintained by listeners subscribing **by class-string** to Offer's
lifecycle events and Inventory's movement events (the seam Inventory already uses to
hear Offer, ADR-048), and rebuilt wholesale by `catalog:refresh-sellability`.

**3. The buy-box price read stops hydrating models.** `buyBoxPricesFor()` is the one
caller asked about the whole catalogue — the price-sorted listing hands it 7,025
products — and it was building an Eloquent `Offer` with its `currency` relation per
row to produce nine values. Six columns and a join answer the same question.

## Consequences

Measured after, same host, same catalogue:

| | before | after |
|---|---|---|
| `sellableProductUuids()` | 24.95s | 0.91s |
| browse (`?per_page=8`) | 22s / 504 | **0.26s** (99ms server-side) |
| browse (`?category=…`) | 10s | **0.27s** |
| browse (`?q=…`) | — | **0.38s** (56ms server-side) |
| browse (`?sort=price_asc`) | — | **0.35s** (was 1.50s mid-fix) |

**The flag is a cache and it is allowed to drift.** Offers, stores and the movement
ledger stay authoritative; `is_sellable` is derived from them. Sellability changes for
reasons nothing announces to Catalog — a store going dark, a reservation ageing out, a
fix script — so the rebuild runs **every ten minutes** and drift heals itself. This is
the answer to the objection the deferred note raised: a second source of truth is
acceptable when it can be rebuilt from the first, and unacceptable when it cannot.

**The failure mode is silent and one-directional.** A flag that only ever cleared
would take a restocked product off the storefront permanently — right in the table,
invisible to every buyer, and nothing to report it. Both directions are tested, and
the column defaults to `false` so a missing backfill hides products rather than
showing unsellable ones.

**Deploying it needs the backfill.** `php artisan migrate` then
`catalog:refresh-sellability`; until that runs the storefront is empty rather than
wrong.

**Still outstanding:** price-sorted browse computes the buy box for every matching
product (7,025) and lands at ~0.35s rather than under 0.2s. The fix is the same shape
one level further — a denormalised minimum price — and it is deliberately not built
here: this ADR already trades one derived column for correctness that a sweep must
maintain, and a second one belongs to its own decision with its own measurement.

# ADR-085 Analytics Is a Single GTM Container, Consent-Gated by Default, and the Only Place a Price Is Parsed

**Status:** Accepted (2026-08-21). Storefront-only; extends ADR-058. Ships with the
storefront (no backend change). Companion to the already-shipped GTM container commit.

**Decision.** The storefront loads exactly one third-party tag: a **Google Tag Manager**
container. GA4 and every future tag are configured *inside* GTM, so adding measurement
never touches code. The integration has three load-bearing properties:

1. **Env-gated on `NEXT_PUBLIC_GTM_ID`.** No id, no container — so `test.raftabul.com`
   (which leaves it unset) never pollutes analytics with staging traffic, and the KVKK
   banner does not appear where there is nothing to consent to. Only production carries
   the real container id and is measured.
2. **Consent Mode v2, denied by default.** A `beforeInteractive` script sets
   `ad_storage`/`ad_user_data`/`ad_personalization`/`analytics_storage` to **denied**
   *before* GTM loads, so no cookie-writing tag can fire until a shopper chooses. The
   KVKK banner (`CookieConsent`) stores the choice in `localStorage` and, on "Kabul Et",
   sends a Consent Mode `update` to granted. A returning grant is re-applied silently on
   every load, because the default resets to denied each time. Denied is the safe state,
   and it is the state a visitor who never answers stays in — measurement is opt-in, not
   opt-out, which is the KVKK-correct stance.
3. **GA4 ecommerce events cross the dataLayer, never a network call the code makes.**
   `view_item` / `add_to_cart` / `begin_checkout` / `purchase` are pushed to
   `window.dataLayer`; GTM (owner-configured GA4 Event tags + triggers) forwards them.
   The code emits signals and owns none of the transport, so a tagging change is never a
   deploy. Every push resets `ecommerce` first (GA4 guidance) and no-ops without a
   dataLayer, so a broken analytics push can never break a page.

**The one exception to "never parse a price."** `money.ts` forbids `Number(price)`
because the API sends money as decimal strings and a float drifts (ADR-005). GA4's
ecommerce spec, however, requires `value`/`price` to be **numeric**. So `analytics.ts`
holds the single, quarantined `analyticsAmount()` — the only `Number()` on a price
string in the storefront — with a comment stating why. It feeds a report, never a total
a shopper sees or a figure the platform keeps, and a malformed amount degrades to `0`
rather than the `NaN` GA4 would drop. Keeping it in one named file is what makes the
rule enforceable: a reviewer greps `Number(` on a price and finds exactly one licensed
site.

**Cost, stated.** The measurement is only as good as the GTM container the owner wires by
hand — the code guarantees the *signals* exist and consent gates them, not that any tag
forwards them; a container published empty measures nothing and the code cannot tell.
And Consent Mode's denied default means the analytics of visitors who decline (or never
answer) are cookieless/absent, which is the correct privacy trade and also a real gap in
the numbers, named here so it is not later mistaken for undercounting.

---

# ADR-086 The Google Merchant Feed Is a Nightly FILE Built From Core Contracts, and It Refuses to Publish Itself Empty

**Status:** Accepted (2026-08-22). Backend; extends ADR-058/060. Work order:
`BUILD_GOOGLE_MERCHANT_FEED.md`.

**Decision.** Google Shopping is fed by an RSS 2.0 file (the `g:` namespace) built by a
scheduled command — `feed:build-google-merchant`, nightly at 04:15 — written to
`storage/app/public/feeds/google-merchant.xml` and handed over by
`GET /feed/google-merchant.xml`. Five properties carry the decision:

1. **A file, not a rendered response.** Twenty thousand items assembled inside a request
   is a request that times out, and it times out *against Google*, whose fetcher records
   the failure against the Merchant Center account. The route does no work: it checks a
   token if one is configured, then streams a file off disk.
2. **Single merchant, buy-box priced.** The platform is the merchant of record
   (ADR-060), so a feed row carries the **buy box winner's** price and never says which
   seller won it. The price is **KDV-inclusive** (ADR-055/061) and therefore carries *no*
   `tax` node — adding one would have Google apply VAT to a VAT-inclusive price.
3. **It lives in Catalog and imports no module.** Title, description, images, brand,
   category path and GTIN are Catalog's own columns, read off its own models; a module
   does not ask itself a question through a Core port. The two facts it does not own
   arrive the way every module gets them — **price through `OfferQueryContract`,
   availability through `InventoryQueryContract`** — batched once per chunk, never per
   row (the ADR-079 lesson). No price or stock *column* enters Catalog, so
   `CatalogBoundaryTest` and `LayeringTest` both stay green.
4. **The build drops what Google would reject, and counts it.** No description (under
   `feed.google.min_description_length`), no image, no live offer, or a category listed
   in `feed.google.excluded_category_slugs` (which excludes its descendants — a policy
   strike lands on a branch, not a leaf). A *rejected* item is worse than an absent one
   because it counts against the account. The drop counts are printed and logged, and
   the "no description" number is the only measure the platform has of its Turkish-copy
   backlog.
5. **An empty feed never replaces a good one.** A run that writes zero items is
   well-formed XML meaning *this merchant sells nothing*, and Google acts on it: the
   catalogue goes from listed to withdrawn, and coming back is a re-review rather than a
   re-fetch. The causes are ordinary and temporary — an unrebuilt `is_sellable` after a
   deploy, an Offer outage, a bad migration — so the build keeps the previous file and
   the command **exits non-zero**. Yesterday's prices are wrong by a day; an empty feed
   is wrong about everything. On a first run there is nothing to keep and the route
   404s, which surfaces as a fetch failure somebody investigates rather than a
   successful fetch of nothing that nobody sees.

The published identifier is the **variant uuid** (ADR-005 §7) and a test asserts no
internal integer id reaches the file. `link` is the flat storefront slug (ADR-059) on
`feed.google.storefront_url` — not `app.url`, which is the API. Shipping is written
explicitly as `0.00 TRY` for TR (ADR-063) rather than left to the Merchant Center
account setting, because the two can disagree and it is the feed a shopper sees quoted.

**Cost, stated.** Price and stock are **a day stale** — a shopper who clicks through to a
changed price sees the storefront's, which Merchant Center tolerates far better than a
fetch that times out; a real-time supplemental feed is deferred. `google_product_category`
is left empty in v1, so Google auto-assigns and some assignments will be wrong until the
mapping is done. The feed is **inert without the scheduler** — the recurring ADR-072
failure — though this one is louder than most, because Merchant Center reports a stale
feed. And the whole thing is gated on catalogue copy: **at the time of writing every one
of the 7,025 sellable products has an empty description**, so the first build published
nothing at all, exactly as designed.

---

# ADR-087 The Review Invitation Is a Nightly Sweep, Asked Once Per Delivered Line, and an Opt-Out Is Recorded Rather Than Skipped

**Status:** Accepted (2026-08-22). Backend; extends ADR-064/066/067. Work order:
`BUILD_REVIEW_REQUEST.md`.

**Decision.** A buyer is emailed once, `settings('reviews.request_delay_days')` (default
3) after delivery, asking them to review what arrived. Five properties carry it:

1. **A sweep, not a delayed job.** The obvious shape — subscribe to `ShipmentDelivered`,
   dispatch a job with a three-day delay — puts the platform's entire review funnel
   inside a queue for three days, where a restarted worker, a flushed Redis or a changed
   setting loses it with nothing to show. A sweep holds no state between runs: it asks
   the same question every night and the answer moves on its own. This is the ADR-072
   shape chosen for the ADR-072 reason, and the same one `loyalty:award-purchase-points`
   already uses for the same class of problem.
2. **The delay is a setting.** Asking on the doorstep asks about a parcel, not a product.
   How long to wait differs by what is being sold, so it is an operator dial rather than
   a constant, read at sweep time — a change moves tomorrow's invitations and never
   re-sends yesterday's. `reviews.request_enabled` is the off switch, so stopping the
   mail is never a deploy.
3. **Idempotent twice over, on `review_requests.order_line_uuid`.** Order re-offers every
   delivered line every night *by design* — a reader that filtered on `review_requests`
   would be Order reaching into Reviews, the same reason `pointsEligibleSellerOrders()`
   re-offers already-credited orders. So Reviews filters, and a UNIQUE index sits
   underneath in case two runs overlap. The check alone is a race; the constraint alone
   is an exception inside a scheduled command. A buyer emailed once is being served; a
   buyer emailed nightly unsubscribes and takes the marketing list with them.
4. **An opt-out is recorded, not skipped.** `BaseNotification` already filters channels by
   preference, so an unsubscribed buyer would receive a notification with no channels —
   nothing sent, but nothing said about why, and the row would read as an invitation that
   went out. The sweep therefore asks first and writes the suppression down:
   `sent_at` null plus `suppressed_reason`. That is what stops the sweep re-evaluating
   the same declining customer nightly forever, and the count is the only measure the
   platform has of what opt-out costs its review funnel. The mail is a service message
   about the recipient's own order, but it sits close enough to the ETK line that
   honouring the marketing opt-out is cheaper than arguing the distinction.
5. **Reviews imports no module.** Delivered lines arrive through a new
   `OrderQueryContract::deliveredLinesForReviewInvitation()` — Order answers it, reading
   the delivery date through `ShipmentQueryContract` exactly as it already does for
   points, so the caller joins one context instead of two. "Already reviewed" is this
   module's own table. The recipient is an `App\Models\Customer`, the authentication tier
   every module may reach.

The invitation links to the storefront's orders page from configuration
(`marketplace.frontend.orders_path`), not to a deep link into one review form: the
storefront owns that flow and its URL, and a backend guessing at a frontend route is how
a mail campaign starts 404ing after a redesign (ADR-025).

**One correction to the work order, recorded rather than silently applied.** The brief
called for "a new `NotificationType::ReviewRequested`". `NotificationType` is the
platform's **channel** enum (ADR-006) — `Database`, `Mail`, `Sms`, `Push`, `Broadcast` —
and its `channel()`, `queue()` and `icon()` maps are exhaustive over it; a notification
*kind* added there would not compile as one and would not mean anything if it did. The
intent is served by a `ReviewRequestedNotification` class on `NotificationType::Mail`,
which is how every other notification on the platform is built.

**Cost, stated.** One invitation per purchase and no reminder: a second email about the
same parcel is where a service message starts reading as marketing, and v1 declines to
find out where that line is — at the price of the reviews a reminder would have won.
Suppressed buyers are never asked again even if they later opt back in, because the row
means "handled" rather than "sent". The sweep is **inert without the scheduler**, and
this is the quietest such failure on the platform: no error, no backlog, no complaint,
just no reviews arriving — indistinguishable from customers who did not feel like
writing one. And it is gated on **SES production access**: until that lands the
invitations queue and fail rather than deliver, so the funnel this exists to open stays
shut.

---

# ADR-088 Product Descriptions Are Generated From the Product's Own Fields, Deterministically, and Say Nothing They Cannot Prove

**Status:** Accepted (2026-08-24). Backend; unblocks ADR-086. Work order:
`BUILD_PRODUCT_DESCRIPTION_TEMPLATE.md`.

**Decision.** The catalogue arrived from a supplier file with six columns and no
description (ADR-074), so every sellable product carried an empty one — which kept the
entire catalogue out of the Google Merchant feed, because Google rejects an item with no
description and the rejection counts against the account. `catalog:fill-descriptions`
writes one for each, assembled from fields the row already holds.

1. **Deterministic, not generated.** Every clause comes from the product's own title,
   brand and category, plus tokens parsed out of that title. Nothing is inferred: a
   product whose title does not state a volume gets a sentence that does not mention
   volume. An LLM would have produced better prose and occasional confident fiction
   across seven thousand rows nobody will read first; this produces plainer sentences
   that are all true.
2. **It claims nothing, and that is law rather than taste.** In Turkey a cosmetic or a
   food supplement asserting that it treats or prevents a disease is a regulatory
   offence. The templates say what a product IS and close with the footer its family is
   required to carry. A test scans every producible string against
   `forbidden_claims` — **which are phrase-shaped patterns, not bare words**, because the
   mandatory supplement footer contains "hastalıkların *tedavisinde kullanılmaz*" and a
   scan for `tedavi` would fail the build on the exact sentence the law requires. A
   negation is the opposite of a claim.
3. **It drives `UpdateProductAction` and writes no model.** The same load-bearing rule as
   the bulk importer (ADR-074) and the seller feed (ADR-076): a query-builder
   `->update()` fires no model events, so Scout never hears — the row would be right in
   the table and stale in search, right in the admin list and wrong to every shopper
   using the search box. The moderation lifecycle is untouched; these products are
   published and stay published, and `UpdateProductAction` carries no status field
   precisely so a content edit cannot move one.
4. **Only empty, never overwritten.** The command fills a hole. Once real copy arrives —
   an editor, a GTIN content source — a run that ignored this would flatten it in bulk
   with no undo. That also makes it idempotent for free: a second run finds nothing.
5. **A family that mixes regulations is not a family.** `anne-ve-bebek` holds baby
   shampoo *and* infant formula. Mapped wholesale to `cosmetic` it produced, on the live
   catalogue, "SMA Comfort 3 Devam Sütü … Haricen kullanım içindir" — a legal footer
   telling a parent that formula is for external use. It is deliberately left unmapped
   and falls through to a family that states only what is true and adds no footer.

**Three corrections the live catalogue forced, recorded because each was silently wrong:**

- **`mg` is not a net quantity.** In a supplement title a milligram figure is the dose per
  capsule; "Tru Niagen 300mg 30 Kapsül" became "Net miktarı 300 mg". The unit is excluded.
- **A multiplier means the parsed figure is not the total.** "12 x 5 ml Ampul" is 60 ml and
  the pattern sees the 5. When a multiplier is present the quantity sentence is dropped.
- **Turkish agglutinates, so whole-word matching finds nothing.** Titles say "Kremi",
  "Jeli", "Şampuanı", "Macunu" — the stem never appears bare, and the form sentence was
  silently never produced. A short **closed** suffix set is allowed; an open one would
  have `jel` match `jelatin` and `toz` match `tozluk`.

**Two deviations from the work order, both deliberate.**

*Scope is every PUBLISHED product, not only the sellable ones.* `is_sellable` is a cache
of current stock and store state rebuilt every ten minutes (ADR-079); scoping a one-off
content backfill to it would make the result depend on the minute it ran, and a product
out of stock that afternoon would be silently missing from the feed when it came back.

*A missing brand does not skip the row.* The brief listed "brand + category" as the
critical pair. Half the catalogue — 3,359 of 7,025 — has no brand, and skipping those
would have left the feed half empty for a sentence that is perfectly true without a
manufacturer's name. Only a missing title or category skips, because only then is there
nothing truthful left to say.

**Cost, stated.** These are template sentences and they read like it: this is **feed
eligibility and an honest floor, not content that ranks**. Organic weight has to come from
category FAQs and accumulated reviews. The same text renders on the product page, so the
product page gains a paragraph of no rhetorical value — accepted, because an empty
description there was worse. When a per-GTIN content source arrives it should overwrite
these, which will need a marker distinguishing generated text from written text; none
exists yet, and adding one later means matching on the closing sentence.

**Result on the live catalogue:** 19,886 descriptions written in under three minutes; the
Merchant feed went from **0 items to 6,967**, with the only remaining exclusions being 58
products that have no image.

---

# ADR-089 Transactional Mail Leaves Over HTTP, Never SMTP, Behind a Failover Chain

**Status:** Accepted (2026-08-24). Backend; supersedes the SMTP assumption in the
go-live runbook. Work order: `BUILD_MAIL_FALLBACK.md`.

**Decision.** Every outbound mail path on this platform is an **HTTP API**, and the
default mailer is a **failover chain: Resend, then SES**.

**The constraint is the host, not the configuration.** Outbound SMTP is blocked upstream:
ports 25, 465, 587, 2465 and 2587 all time out to three separate providers, while 22, 53
and 443 are open, `ufw` is `ACCEPT` on output and the local chains are empty. No
credential fixes that, which is why the platform moved to SES over HTTPS in the first
place — and why any fallback provider must also be an HTTP API rather than another SMTP
host.

**Resend leads because it is the one that can deliver.** AWS **refused** SES production
access, leaving that account able to reach only verified addresses — fine for a smoke
test, useless for a customer resetting a password. SES stays in the chain behind Resend:
it costs nothing while it cannot deliver, and the day the account is approved it becomes
a real second provider with no deploy.

**`smtp` is removed from the chain, and that is a fix rather than tidying.** The failover
transport tries each mailer in turn, so a dead port in the list is not a harmless extra
attempt — every message burned a full connection timeout on a port that cannot open
before reaching the mailer that works, turning a fast failure into a slow one, per
message, on a queue.

**The API key is read under both documented names.** `resend/resend-laravel` reads
`RESEND_API_KEY` through its own merged config; Laravel documents `RESEND_KEY` via
`services.resend.key`. Both resolve, because an owner typing the name from either set of
docs should get a working mailer rather than "The Resend API key is missing" — and the
credential is typed on the server by its owner, never through this repository or a chat.

**Cost, stated.** Two providers means two DNS identities to keep valid: Resend's DKIM
records join the existing SPF/DMARC in Cloudflare, and a lapse there fails silently into
spam rather than loudly into an error. Deliverability now depends on a vendor the platform
has no contract-level control over, and the failover only covers **transport** failures —
a message Resend accepts and then bounces never reaches SES, because as far as the
application is concerned it was sent. Suppression and bounce handling live with the
provider rather than in this codebase; the webhooks that would bring them back in are not
built.

**Nothing is delivered until the owner completes two steps this repository cannot do:**
the `RESEND_API_KEY` in the production `.env`, and Resend's DKIM records in Cloudflare
DNS. Until both land, mail continues to queue and fail — visibly, in `failed_jobs`, rather
than silently.

**Live since 2026-08-24.** Both owner steps landed the same day — the key is in the
production `.env` and the Resend domain is verified — so prod now runs
`MAIL_MAILER=failover`. Verified in that order: a raw send through the `resend` mailer
**by name**, not through the chain, so the success proves Resend accepted it rather than
SES quietly catching a fall; then a real registration and a real password reset against
the live API, both delivered with nothing new in `failed_jobs`. The **six** notifications
that had accumulated there were all `VerifyEmailNotification` refused by SES with `403`
between 20 and 21 August — real people who signed up and never got a link. `queue:retry
all` cleared them to zero, and their links were **not** stale: the signed URL is built
when the job runs, not when it is queued.

**A rejected RECIPIENT is not a local failure, and that is the second thing this chain
taught (2026-08-24).** `retry_after` is not a backoff, it is a **blast radius**: Symfony's
round-robin marks a transport that threw as dead for that many seconds and will not ask
it again in the window. Four leftover `@example.com` registrations proved what that
means — Resend rejected the address, SES answered `403` behind it, and the next real
notifications in the same burst died with **"No transports found"** without either
provider being contacted. Sixty seconds of a marketplace's order and password mail, lost
to an address nobody was ever going to read.

Two changes, in that order of importance. **`BlockedRecipientGuard` drops undeliverable
recipients before a transport is asked** — a `MessageSending` listener reading
`mail.blocked_recipient_domains` (the RFC 2606 reserved domains plus the common
disposable ones). It **removes recipients rather than cancelling messages**, so a real
customer who happens to share a CC with a test address still gets their mail, and it
**never throws**, because a queued notification that raised here would retry an address
that will never exist until it exhausted its tries. And **`retry_after` drops 60 → 5**,
which keeps the point of the round-robin — a provider in a genuine outage is not hammered
once per message — while making the window too short to swallow a burst. Belt and braces,
deliberately: the guard stops the messages we can predict, the shorter window limits what
an unpredicted one costs.

The same config list defines what `users:purge-test-accounts` treats as disposable, so
"not a real address" has **one** definition here rather than two that drift — at the cost
that adding a domain to it now does two things. That command soft-deletes only test-domain
customers with **no orders, payments, points, reviews, questions or returns**; a fake-looking
address with history behind it is kept, because a soft-deleted user under a real order is a
support call rather than a tidy database. Work order: `BUILD_MAIL_RESILIENCE.md`.

**One failure mode the chain does not cover, learned here.** A missing or unset
`RESEND_API_KEY` does not fail over to SES — it kills the send outright.
`resend-laravel` resolves its client lazily and throws `ApiKeyIsMissing extends
InvalidArgumentException`, while Symfony's failover transport catches only
`TransportExceptionInterface`. So `MAIL_MAILER=failover` without the key is **worse than
`ses`**, not a degraded version of it, and the two must be set in that order.

---

# ADR-090 Free-Text Search Runs Through a Typo-Tolerant Engine, Ranked, With the Database Fold as Its Fallback

**Status:** Accepted (2026-08-27). Ratified from
`docs/ADR-090-search-engine.DRAFT.md`, which this entry replaces. Backend +
infrastructure. Work orders: `BUILD_SEARCH_TURKISH.md` (tier 1),
`BUILD_SEARCH_MEILISEARCH.md` (tier 2).

**Decision.** The buyer's free-text (`q`) path runs on **Meilisearch through Laravel
Scout**, and the tier-1 folded `search_text` LIKE (ADR-089) stays in the codebase as its
**fallback**.

**Why an engine at all.** The fold fixed the catastrophe — `gunes` finds `güneş`, `avene`
finds `Avène` — but a folded `LIKE '%…%'` is a substring filter, not search. Three things
it cannot do, each measured missing on the live catalogue: **typo tolerance** (`seurm`,
`uriaj`, `depidem` all returned nothing), **relevance order** (a brand query buried its own
exact match among a hundred rows), and **as-you-type suggestion**. Those are what an engine
is.

**Meilisearch, self-hosted, on loopback.** A single binary, an official Scout driver, typo
tolerance, synonyms, prefix search and configurable ranking out of the box. Typesense is
the near-equal kept for the day vector search is actually needed; Algolia is rejected
because it is priced per search and would ship the catalogue to a third party. One process
serves both environments, separated by `SCOUT_PREFIX` — a second instance would double
resident memory on a 7 GB box to isolate what a prefix already isolates.

**The engine ranks; the database still decides who may see it.** Meilisearch returns
relevance-ranked Catalog **uuids**; the Offer-aware listing then applies the sellable wall,
category, brand and price, and — when the shopper has not chosen a sort — preserves the
engine's order. Choosing a price sort keeps the engine's SET and takes the shopper's ORDER.
**Price and stock are never indexed**: they belong to Offer, one product has as many prices
as it has sellers, and `CatalogBoundaryTest` fails the build if they leak. The one custom
ranking rule is `is_sellable:desc` — Catalog's own denormalised buyability flag (ADR-079),
which is what lets ranking prefer buyable products without Offer data crossing the
boundary.

**`null` means no engine; `[]` means no results.** That distinction is the resilience
design. `ProductSearchContract::rankedUuids()` answers `null` when Scout has no driver or
the engine throws, and the listing then falls back to the fold — worse search, never an
empty page. An empty array is the engine's real answer and is honoured as one.

**The four questions the draft left open, answered.** (1) Meilisearch confirmed for v1.
(2) The Meili→Offer reconciliation ships as designed, with pagination over the filtered,
re-ordered set tested at page 2. (3) Synonyms are **version-controlled config**
(`config/catalog.php`), pushed by `search:sync-settings`; an admin surface is v2. (4)
Degraded search is observable two ways: every fallback logs a **warning**, and
`GET /api/v1/health` reports `search: up | down | disabled` — `disabled` being the rollout
state, not a fault.

**Cost, stated.** A second datastore that can drift from the catalogue, and consistency
that is only ever eventual — a moderation change reaches the index when the `search` queue
drains, so **a queue worker is part of the feature** (the ADR-074 lesson). Degraded search
running silently is its own trap, which is what the warning and the health field exist for.
Synonyms and ranking are editorial and go stale quietly. And it is still lexical search: it
matches words, not meaning.

**Not built, deliberately.** A sales-count ranking rule — the work order allows one, but
that number lives in Order and Catalog holds no synced copy; building the sync is its own
change with its own drift story. Semantic/vector search and personalised ranking stay out.
The fold is **not** deleted. The seller-facing picker (`CatalogBrowse`) stays on the fold
too: it is an internal panel where exactness beats typo tolerance, and it must keep working
when the engine does not.

---

# ADR-091 A Server-Side Render Is First-Party Traffic, and the Rate Limiter Counts Outsiders

**Status:** Accepted (2026-09-04). Ratified from
`docs/ADR-091-internal-render-trust.DRAFT.md`, which this entry replaces. Backend +
infrastructure. Implemented 2026-09-03 (`128d327`) as an outage fix; written down
after the fact because it changes the trust boundary of a security control. Work
order: `BUILD_LISTING_500_ROOTCAUSE.md`.

**Decision.** A request that originates from this platform's own server-side render is
**first-party traffic and the public `storefront` rate limiter does not count it**. The
exemption is keyed on a **CGI parameter set by the loopback-only vhost**
(`fastcgi_param INTERNAL_RENDER 1`) — not on the client address, and not on a request
header.

**What it cost to find.** `/urunler` answered "Application error: a server-side exception
has occurred" on the live site, fast (~0.4s) and **only under load**, while every external
measurement said the platform was healthy: thirty concurrent `GET /api/v1/products` all
200, home and product pages fine, feeds fine. The report proposed PHP-FPM saturation, a
dropped loopback socket, or a wrong `INTERNAL_API_URL`. It was none of them. Digest
`2264697604` resolved in the Next.js log to `POST /api/v1/offers/prices failed status:
429` — **11,094 of them**. The measurements were right; the reading of them was wrong.

**The mechanism is topology, not capacity.** The storefront renders on the same box and
fetches the API over loopback, so to the API **every shopper's render is 127.0.0.1** and
they all shared one 300/minute bucket. A listing render spends two of those calls
(`/products`, then the bulk price call for the page), so a few dozen shoppers a minute
exhausted the minute's budget for everyone. Two details hid it: an outside browser has its
own IP and was never throttled the same way, so curl always looked healthy; and the home
page **swallows** its fetch errors and renders an empty strip, while the listing threw —
only one page turned a 429 into a 500.

**Why a CGI parameter and not the obvious signals.** *Not the socket address*: every
request reaches PHP-FPM from nginx over loopback, a shopper's included, so `REMOTE_ADDR`
cannot tell a render from a visitor — on this topology an IP exemption is either useless or
a hole. *Not a request header*: a client can send anything, and nginx exposes request
headers to PHP as `HTTP_*`, so a client-supplied `Internal-Render: 1` arrives as
`HTTP_INTERNAL_RENDER` and can never collide with the bare `INTERNAL_RENDER` the vhost
sets — **a test asserts exactly that**, and it is the assertion that keeps this safe rather
than merely convenient. *Not a shared secret*: that would be a credential to rotate, leak
and forget. The listener is bound to 127.0.0.1, so the trust boundary is enforced by the
network; the CGI variable only reports which side of it a request came from.

**No route becomes unthrottled.** The limiter stays installed everywhere (the
`routes/api.php` rule holds); one *class of client* stops being counted.
`RateLimiter::for('storefront')` returns `Limit::none()` when `INTERNAL_RENDER` is present
and the ordinary per-IP limit otherwise.

**Cost, stated.** A **runaway server-side render is no longer bounded here** — a future page
that loops over the price endpoint will show up as load on our own box rather than as a
429. That is the right way round: it would be our bug, on our hardware, visible in metrics,
while the limiter exists to bound scraping *from outside*, which still counts every
browser-direct call by its real IP. The protection now **depends on nginx configuration
that lives outside this repository**: rebuild the loopback vhost without the
`fastcgi_param` line and the 429 storm returns; set the same parameter on a **public**
listener and the storefront limiter silently protects nothing. Both are one grep away, and
both are why the parameter is named after what it means. And **300/minute has never been
measured against browser traffic alone** — it was tuned while it was accidentally also
throttling every render, so it may now be too generous for scraping. Unknown, and worth
measuring rather than guessing.

**The capacity question is deferred, not answered.** A listing page still costs two API
round trips per render and the price call is uncached; this removed an artificial ceiling,
not a real one. When traffic makes it matter, the answer is caching the buy-box prices per
page (ADR-079's shape), not a bigger number here.

**Not done, deliberately.** The other limiters (`api`, `panel`, `search`, `auth`) are
unchanged — no server-side render reaches them today, and when one does it gets the same
treatment deliberately rather than through a shared helper nobody reads. Caching the price
call and edge rate limiting (Cloudflare already sits in front, and is where a real abuse
response belongs) stay separate decisions. **Open follow-up:** a boot-time or health-check
assertion that the loopback path still carries `INTERNAL_RENDER`, so a rebuilt vhost is
caught in minutes instead of in an outage.
