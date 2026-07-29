# Organization Module Specification
Version: 1.0 — **feature-complete and FROZEN** (Phases 0–8 built)

> ## 🧊 Organization is frozen
>
> The Organization sprint is closed; the module is feature-complete (Phases 1–8,
> ADR-028–031). Only **bug fixes, security fixes, compatibility updates, or
> changes a later module explicitly requires** are permitted — no new features.
> The next major sprint is the **Store** module (which consumes
> `StoreOpeningApproved` to create the actual storefront — ADR-028).
>
> **Two documented follow-ups** (non-blocking, not gaps in the security model):
> - `OrganizationSettings` (§2.6) was never built — its endpoints are omitted
>   rather than introducing a domain concept during presentation work.
> - The Activity user-timeline listener for Organization events is deferred: the
>   forensic **Audit** trail (every aggregate is `Auditable`) is complete and is
>   the compliance record; Activity is additive narrative.
>
> **Owner-approved refinements after freeze (2026-07-29)** — Presentation, plus one
> action; the Domain is otherwise untouched, `StoreOpeningApproved`/ADR-028 preserved:
> - **Team member management** gains an **edit role** action (the existing
>   `ChangeMemberRoleAction`) and **deactivate/reactivate** (a new
>   `ChangeMemberStatusAction` over the member's existing `status`), alongside the
>   existing **remove**.
> - **Seller onboarding reflow**: the "Yeni Organizasyon" form collects **required
>   store info** and, on submit, creates the org **and** its Store Opening Request in
>   one step (reusing `CreateStoreOpeningRequestAction`). The standalone SOR create
>   page leaves the seller nav; a store is still created **only** by
>   `StoreOpeningApproved` (ADR-028). See [Store.md](Store.md) for the "Yeni Mağaza
>   Talep Et" entry point on the seller Stores page.

Governed by: `CLAUDE.md` → `Architecture_Decision_Record.md` →
`001_Architecture.md` → `003_Database_Standards.md` → `002_Coding_Standards.md`
→ `004_Naming_Conventions.md` → `005_API_Standards.md` → this document
(ADR-003).

> ## ✅ Architecture approved — Organization is unfrozen for implementation
>
> Both pre-implementation decisions are **approved and applied**:
>
> 1. **ADR-028 is ratified** — recorded in
>    `docs/Architecture_Decision_Record.md` and the `001_Architecture.md`
>    amendment log. It is the canonical rule for Organization ↔ Store (§0.2).
> 2. **`BaseNotification` moved to Core** —
>    `App\Core\Application\Notifications\BaseNotification`. It is platform
>    infrastructure, not Notification-module business logic. Identity,
>    Organization and every future module depend on the **Core** base; the
>    Notification module owns delivery only. The latent module-isolation
>    violation is **removed at the root**, not excepted — the layering rules are
>    unweakened.
>
> One standing boundary note (not a blocker):
>
> - **Store module does not exist yet.** Organization emits `StoreOpeningApproved`
>   and never touches Store. The `Store` model is created by the future Store
>   module consuming that event (§0.2, §1, §7). Until then an approved request's
>   event fires into no listener — by design (an event with no subscriber is not
>   an error).

---

# 0. Scope and the one new decision

## 0.1 What an Organization is

An **Organization is the legal seller company** — the registered business
entity that a marketplace seller operates as. It carries the company's legal
identity (tax number, trade registry, MERSIS), its KYC/verification state, its
members and their roles, its bank account for payouts, and its store allowance.

**An Organization is NOT a Store.** A Store is a storefront — a branded selling
surface with a catalogue. One Organization may own **many** Stores. The
Organization is the *who* (the company); the Store is the *where* (the shop).

```
User (Seller)  ──owns──▶  Organization  ──owns──▶  Store, Store, Store …
                              │
                              ├── OrganizationMember (Seller / Seller Employee)
                              ├── OrganizationInvitation
                              ├── OrganizationDocument (KYC)
                              ├── OrganizationBankAccount (payouts)
                              ├── OrganizationSettings
                              └── StoreOpeningRequest ──(on approval)──▶ Store
```

## 0.2 ADR-028 — Stores are created only by admin approval of a request

> **ADR-028 is ratified** (in `Architecture_Decision_Record.md` + the
> `001_Architecture.md` amendment log). The rule below is canonical.

**Decision.** An Organization may **never** create a Store directly. It may only
submit a **Store Opening Request**. An administrator reviews the request. A Store
is created **only** after approval. Store creation is **never** automatic.

```
Organization ──submits──▶ StoreOpeningRequest (Pending)
                               │
                     Admin reviews
                          ┌────┴────┐
                     Approved     Rejected
                          │            │
          StoreOpeningApproved event   StoreOpeningRejected event
                          │
             Store module creates the Store (future)
```

**Store limits.** An Organization has a maximum number of Stores it may operate.
The architecture supports **both** a system-wide default **and** an
organization-specific limit, resolved in this order (first non-null wins):

1. **Per-organization override** — `organizations.store_limit_override`
   (nullable). An admin grants a bespoke allowance.
2. **Plan limit** — the `store_limit` of the Organization's `OrganizationPlan`
   (nullable → *unlimited*).
3. **System default** — `config('marketplace.organization.default_store_limit')`.

Example plans (operator-configurable, not hardcoded — §2.8):

| Plan | `store_limit` | Meaning |
|---|---|---|
| Starter | 1 | one Store |
| Business | 5 | up to five Stores |
| Enterprise | `null` | unlimited |

The limit is enforced when a Store Opening Request is **approved** (the moment a
Store would come into existence), and *also* checked at **submission** to fail
fast — but approval is the authoritative gate, because limits or plans may change
while a request sits pending.

**Cost of this decision.** Two-step store creation is slower for the seller and
adds an admin workload (a review queue). That is deliberate: a marketplace's
trust depends on every storefront having passed a human check, and an
automatically-created Store is an automatically-created liability.

---

# 1. Purpose

## 1.1 Responsibilities

Organization owns:

- The **legal company entity** and its lifecycle (Pending → Approved → … →
  Archived), including soft-delete and restore.
- **Membership**: which users belong to the company and in what internal role,
  and the invitation workflow that adds them.
- **KYC / verification**: the company's legal identifiers and uploaded documents,
  and the admin approval that turns a Pending organization into an Approved one.
- **Bank account** for payouts (the account details; not the payouts
  themselves — that is Finance).
- **Organization settings** — per-company operational preferences.
- **Store Opening Requests** — the request lifecycle and its admin review
  (ADR-028). *Not* the Store itself.
- **The store allowance** — resolving and enforcing the maximum-stores limit.

## 1.2 Non-responsibilities

Organization does **not** own:

- **Stores.** Their model, catalogue, and storefront belong to the Store module.
  Organization only requests them and counts them.
- **`App\Models\User`** and authentication. That is Identity + `app/Models`. A
  member *is* a `User`; Organization links to users, it does not define them.
- **Payouts, commission, invoices, wallet balances.** Those are Finance. The bank
  account lives here; the money movement does not.
- **Products, offers, orders, payments.** Later modules.
- **File storage mechanics.** Uploads go through `App\Shared\Traits\HasMedia`
  (private disk, signed URLs); Organization never re-implements storage.
- **Platform authentication roles/guards.** The org-internal roles (§5) are a
  membership concept, entirely separate from the Spatie guard roles.

## 1.3 Module boundaries

Enforced by `tests/Architecture/LayeringTest.php`. Organization may import:

- `app/Models/User` (and the `Seller`/`SellerEmployee` subclasses) — a member is
  a user; this is the same allowance Identity has.
- `app/Core`, `app/Shared` — bases, traits (`HasMedia`, `HasUuid`, `HasStatus`),
  enums (`UserType`, `Status` where generic), `PermissionRegistry`.
- `App\Modules\Localization\Domain` — countries/currencies (the one permitted
  cross-module dependency, §"Enums vs lookup tables").

Organization may **NOT** import Identity, Store, Settings, Audit, Activity,
Media, Notification internals. Cross-module communication is via **domain
events** (§8). The exceptions are the same consumer-direction ones the platform
already grants: Audit/Activity **subscribe** to Organization's events (they
import Organization's `Domain\Events`, never the reverse).

## 1.4 Relationship with Identity

- A member is a `User` on the **seller** guard (`Seller` owner and staff, or
  `SellerEmployee`). Organization stores the `user_id`; Identity owns the user.
- Organization reacts to Identity events where relevant (e.g. it must not leave a
  member row pointing at a hard-deleted user — it subscribes to `UserDeleted`).
- Organization never authenticates. A seller signs in through Identity; their
  organization membership is resolved *after* authentication.
- **Layering:** if Organization needs to *read* a user, it does so through
  `app/Models/User` (allowed), never through Identity's repositories or services.

## 1.5 Relationship with Store (future)

- Organization owns `StoreOpeningRequest`. It emits `StoreOpeningApproved` /
  `StoreOpeningRejected`. It never imports Store, never creates a `Store`.
- The Store module (future) **subscribes** to `StoreOpeningApproved` and creates
  the Store, then reports back via its own event (e.g. `StoreCreated`) which
  Organization may subscribe to in order to increment its store count / link the
  request to the created store's UUID.
- Until the Store module exists, the request lifecycle is fully functional and
  the event fires into no listener — by design (an event with no subscriber is
  not an error, §"Events between modules").

## 1.6 Relationship with Foundation (the module group)

- **Localization** — the company's country/currency reference the Localization
  tables (imported, permitted).
- **Media** — KYC documents and the org logo use `App\Shared\Traits\HasMedia`;
  private documents (tax certificate, ID scans) live on the private disk behind
  signed URLs, exactly as `docs/…media` prescribes.
- **Audit** — Organization models are `Auditable`; admin actions carry a
  `reason` (ADR-027). Audit subscribes to Organization's security-relevant
  events.
- **Activity** — Activity subscribes to Organization's events for the member/
  owner timeline.
- **Notification** — Organization sends notifications via the platform base
  (§14) — extending `App\Core\Application\Notifications\BaseNotification`.
- **Settings** — platform-wide settings via `settings()`; org-specific settings
  are `OrganizationSettings` (this module), not the Settings module.

---

# 2. Domain Model

All public identifiers are **UUIDs**; the internal `id` never leaves the
application (ADR §8). All models are `Auditable` unless noted. Money is never
stored here (§"Non-responsibilities"); the only financial datum is the bank
account's IBAN, a string.

## 2.1 `Organization`

The legal company. One per registered seller business.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | internal only |
| `uuid` | uuid, unique | public id |
| `owner_id` | FK → users.id | the sole Owner (a Seller). **NOT NULL** — an org cannot exist without an owner (§3.9) |
| `legal_name` | string | registered company name |
| `display_name` | string, nullable | trading name; falls back to `legal_name` |
| `slug` | string, unique | url-safe handle |
| `status` | `OrganizationStatus` | Pending / Approved / Rejected / Suspended / Archived (§2 enum note) |
| `plan_id` | FK → organization_plans.id, nullable | null → system default limit |
| `store_limit_override` | integer, nullable | admin-granted bespoke allowance; wins over plan |
| `country_id` | FK → countries.id | Localization |
| `currency_id` | FK → currencies.id | Localization |
| `verified_at` | timestampTz, nullable | set on KYC approval |
| `suspended_at` | timestampTz, nullable | |
| `approved_by` / `rejected_by` / `suspended_by` | FK → users.id, nullable | admin actor |
| `rejection_reason` | text, nullable | |
| timestamps + `deleted_at` | | soft-deletes |

Relations: `owner()` (BelongsTo User), `members()` (HasMany), `invitations()`,
`documents()`, `bankAccount()` (HasOne), `settings()` (HasOne), `plan()`,
`storeOpeningRequests()`, `country()`, `currency()`.

Derived: `effectiveStoreLimit(): ?int` (override → plan → config; null =
unlimited), `remainingStoreSlots(): ?int`, `canRequestStore(): bool`.

**Status is a module-specific enum**, not the shared `Status` — the shared enum
has no `Approved`/`Rejected`, and an organization's lifecycle is its own (the
`OrderStatus` precedent, CLAUDE.md "enum or lookup table"). `OrganizationStatus`
carries no `Enum` suffix (ADR-007).

## 2.2 `OrganizationMember`

The pivot linking a `User` to an `Organization` with an internal role.

| Column | Type | Notes |
|---|---|---|
| `id` / `uuid` | | |
| `organization_id` | FK | |
| `user_id` | FK → users.id | a Seller or SellerEmployee |
| `role` | `OrganizationRole` | Owner / Manager / Finance / … (§5) — module-owned enum, **not Spatie** (`teams=false`) |
| `status` | `OrganizationMemberStatus` | Active / Suspended |
| `invited_by` | FK → users.id, nullable | |
| `joined_at` | timestampTz | |
| timestamps + `deleted_at` | | |

Unique constraint `(organization_id, user_id)` — a user is a member of an org at
most once. The Owner is the member whose `role = Owner`; exactly one exists per
org (§3.9).

## 2.3 `OrganizationInvitation`

A pending offer of membership.

| Column | Type | Notes |
|---|---|---|
| `id` / `uuid` | | |
| `organization_id` | FK | |
| `email` | string | normalised (lower/trim) |
| `role` | `OrganizationRole` | the role they will hold |
| `token_hash` | string | **hashed** acceptance token — the raw token is emailed, never stored, never returned (ADR-025) |
| `status` | `OrganizationInvitationStatus` | Pending / Accepted / Rejected / Expired / Cancelled |
| `invited_by` | FK → users.id | |
| `expires_at` | timestampTz | |
| `accepted_at` / `accepted_by` | | |
| timestamps | | append-mostly; invitations are not soft-deleted, they are Cancelled/Expired |

Security: the token travels **out of band** (email) only; the API never returns
it (ADR-025). Issuing a new invitation to the same email invalidates the prior
pending one.

## 2.4 `OrganizationDocument`

A KYC / legal document upload.

| Column | Type | Notes |
|---|---|---|
| `id` / `uuid` | | |
| `organization_id` | FK | |
| `type` | `OrganizationDocumentType` | tax_certificate / trade_registry / signature_circular / id_document / … |
| `media` | via `HasMedia` (private disk) | signed-URL access only |
| `status` | `OrganizationDocumentStatus` | Pending / Approved / Rejected |
| `reviewed_by` | FK → users.id, nullable | admin |
| `review_notes` | text, nullable | |
| timestamps + `deleted_at` | | |

The file itself is never public; access is a short-lived signed URL
(`config('marketplace.media.signed_url_ttl')`).

## 2.5 `OrganizationBankAccount`

Payout destination. **One per organization** (HasOne).

| Column | Type | Notes |
|---|---|---|
| `id` / `uuid` | | |
| `organization_id` | FK, unique | |
| `account_holder` | string | |
| `iban` | string, **encrypted** (Infrastructure cast, ADR-019) | a payout credential |
| `bank_name` | string, nullable | |
| `currency_id` | FK → currencies.id | |
| `verified_at` | timestampTz, nullable | |
| timestamps + `deleted_at` | | |

`iban` is **encrypted at rest** and **excluded from the audit trail** (like
`password` — an audit row must never become a credential store, ADR-027 /
`docs/audit.md`). The last four digits may be surfaced; the full IBAN never
leaves the API in a readable form to non-owners.

## 2.6 `OrganizationSettings`

Per-organization operational preferences (HasOne). A row, not the Settings
module — these are business data owned by the org, not platform config.

| Column | Type | Notes |
|---|---|---|
| `organization_id` | FK, unique | |
| `notification_email` | string, nullable | where org notices go if not the owner |
| `default_store_category_id` | FK, nullable | convenience default for requests |
| `locale_overrides` | jsonb, nullable | |
| … | | extensible |

## 2.7 `StoreOpeningRequest`

The request to open a Store (ADR-028). Lives here; the Store does not.

| Column | Type | Notes |
|---|---|---|
| `id` / `uuid` | | |
| `organization_id` | FK | |
| `requested_by` | FK → users.id | the member who submitted |
| `status` | `StoreOpeningRequestStatus` | Draft / Submitted / Pending / Approved / Rejected / Cancelled |
| `store_name` | string | requested name |
| `slug` | string | requested handle (uniqueness re-checked by Store on creation) |
| `category_id` | FK, nullable | proposed category |
| `description` | text, nullable | |
| `reason` | text, nullable | seller's justification |
| `logo` | via `HasMedia`, nullable | proposed logo |
| `admin_notes` | text, nullable | reviewer notes |
| `reviewed_by` | FK → users.id, nullable | |
| `submitted_at` / `approved_at` / `rejected_at` | | |
| `created_store_uuid` | uuid, nullable | filled when the Store module reports back |
| timestamps + `deleted_at` | | |

`created_store_uuid` is a **UUID reference**, not a foreign key — Organization
must not have a schema dependency on a Store table it does not own.

## 2.8 `OrganizationPlan`

**A lookup table, not an enum** — an operator must be able to add, disable or
re-price a plan and change its store limit **without a release** (CLAUDE.md
"enum or lookup table"; `is_active`, ADR-015).

| Column | Type | Notes |
|---|---|---|
| `id` / `uuid` | | |
| `name` | string | Starter / Business / Enterprise |
| `slug` | string, unique | |
| `store_limit` | integer, **nullable** | null → unlimited |
| `is_active` | boolean | operator toggle |
| `sort_order` | integer | |
| timestamps | | |

## 2.9 Enums (module-owned, no `Enum` suffix — ADR-007)

- `OrganizationStatus` — Pending, Approved, Rejected, Suspended, Archived
- `OrganizationMemberStatus` — Active, Suspended
- `OrganizationRole` — Owner, Manager, Finance, Warehouse, Support, Marketing,
  Editor, Viewer (§5)
- `OrganizationInvitationStatus` — Pending, Accepted, Rejected, Expired,
  Cancelled
- `OrganizationDocumentType` — tax_certificate, trade_registry,
  signature_circular, id_document, other
- `OrganizationDocumentStatus` — Pending, Approved, Rejected
- `StoreOpeningRequestStatus` — Draft, Submitted, Pending, Approved, Rejected,
  Cancelled

Each is a backed string enum using `HasEnumHelpers`, living in
`App\Modules\Organization\Domain\Enums`. Where an enum crosses a module boundary
on an event (e.g. `OrganizationRole` referenced by an Activity listener), it is
promoted to `App\Shared\Enums` — the `LoginThreatKind` precedent.

---

# 3. Business Rules

## 3.1 Organization lifecycle

```
        register
           │
        Pending ──approve──▶ Approved ──suspend──▶ Suspended
           │                    │  ▲                   │
        reject                  │  └────restore────────┘
           │                    │
        Rejected            archive
                                │
                            Archived
```

- **Pending** — created, KYC submitted or in progress, not yet operational. May
  not submit Store Opening Requests.
- **Approved** — KYC passed; fully operational; may submit requests within its
  limit.
- **Rejected** — KYC failed; terminal unless re-opened by admin; `rejection_reason`
  required.
- **Suspended** — temporarily disabled by admin (policy breach, dispute); members
  cannot act; existing stores handled by Store module policy. Restorable.
- **Archived** — retired by the owner or admin; read-only; a business end-state
  distinct from soft-delete.

## 3.2 Soft-delete policy

`deleted_at` is a **recoverable removal**, orthogonal to `status`. Soft-deleting
an Organization cascades logically (members, invitations, documents, requests are
scoped out) but does **not** hard-delete rows — dispute evidence and the audit
trail must survive. Hard delete is prohibited outside a retention job.

## 3.3 Restore policy

Restoring un-soft-deletes and returns the org to its prior `status`. Restoring is
an admin action (or owner within a grace window — decided in Organization-1).
Restore is audited with actor + reason.

## 3.4 Approval workflow (KYC → Approved)

1. Owner completes company details + uploads required documents → org is
   **Pending**.
2. Admin reviews (documents, tax number, MERSIS). Each document may be
   individually Approved/Rejected.
3. Admin **approves** the organization → `status = Approved`, `verified_at` set,
   `approved_by` recorded, `OrganizationApproved` dispatched, owner notified.
4. Admin **rejects** → `status = Rejected`, `rejection_reason` required,
   `OrganizationRejected` dispatched, owner notified.

Approval is idempotent-safe and fully audited (actor, reason, correlation id).

## 3.5 Store opening workflow (ADR-028)

1. A member with `store_request.create` capability submits a request → Pending
   (fail-fast limit check at submission).
2. Admin reviews → Approve or Reject.
3. On **approve**: authoritative limit re-check; `StoreOpeningApproved`
   dispatched; the Store module creates the Store and reports back
   (`created_store_uuid` filled).
4. On **reject**: `admin_notes`/reason required; `StoreOpeningRejected`
   dispatched; seller notified.

A request may be **Cancelled** by the org while still Draft/Submitted/Pending.

## 3.6 Invitation workflow

Invite → (email, hashed token, expiry) → Accept / Reject / Expire / Cancel /
Re-send. Detailed in §6.

## 3.7 Organization ownership

Exactly **one Owner** per organization at all times. The Owner is a `Seller`
user (never a `SellerEmployee`, never an `Admin`). The Owner holds every
org-capability implicitly (§5), analogous to Super Admin at platform level but
scoped to the one organization.

## 3.8 Member permissions

Derived from the member's `OrganizationRole` via the permission matrix (§5).
**Policies check the derived org-capability, never the role name** — the platform
rule ("policies check permissions, never roles") applies inside the org too. The
Owner bypasses the matrix (implicit-all).

## 3.9 The owner invariant

**An Organization can never exist without an Owner.** Enforced at three levels:

- `owner_id` is `NOT NULL`.
- Removing the Owner is impossible; the only way to change who owns the org is
  **owner transfer** (§3.10), which is atomic.
- The Owner's membership row cannot be deleted while they are the Owner.

## 3.10 Changing / removing the owner

- **Transfer**: `TransferOwnershipAction` atomically demotes the current Owner to
  a chosen role (default Manager) and promotes the target member to Owner. Both
  must be Active members; the target must be a `Seller` (a `SellerEmployee`
  cannot own). Audited with reason; `OrganizationOwnerTransferred` dispatched.
- **Removing the owner** is not an operation — it is a transfer. Any attempt to
  delete the Owner's membership fails the policy and the model guard.

## 3.11 Maximum stores

`effectiveStoreLimit()` resolves override → plan → config default (§0.2). Checked
at submission (fail-fast) and authoritatively at approval. `null` = unlimited.

---

# 4. Organization Verification (KYC)

## 4.1 Fields

| Field | Storage | Notes |
|---|---|---|
| Tax number | `organizations` (or a `kyc` sub-table) | validated by format; jurisdiction-specific |
| Company (legal) name | `organizations.legal_name` | |
| Tax office | string | TR: vergi dairesi |
| Trade registry no. | string | TR: ticaret sicil |
| MERSIS number | string | TR central registry id; format-validated |
| IBAN | `OrganizationBankAccount.iban` (encrypted) | payout, §2.5 |
| Authorized person | name + national id (encrypted) | the signatory |
| Identity verification | document + admin check | |
| Document uploads | `OrganizationDocument` (private disk) | §2.4 |

National id and IBAN are **encrypted at rest** and **audit-excluded**.

## 4.2 Admin approval

The KYC review is the §3.4 approval workflow. Each document has its own
Pending/Approved/Rejected state; the org is Approved only when the admin approves
the org as a whole. Admin rejection requires a reason.

## 4.3 Future extensibility

- **Jurisdiction abstraction.** TR fields (MERSIS, tax office, trade registry)
  are the first implementation; the KYC field set must be structured so other
  countries add their identifiers without an org-table migration (candidate: a
  `kyc_fields` jsonb validated by a per-country ruleset). Decided in
  Organization-1; the spec only mandates that the design not hardcode TR.
- **Automated verification** (e-Government / MERSIS API lookup) is a later hook —
  a `VerificationProviderContract` seam, defined but not implemented, mirroring
  Identity's `TotpProviderContract` abstraction.

---

# 5. Roles inside the Organization

**These are membership roles, wholly separate from the platform's Spatie guard
roles.** Spatie `teams` is `false`, so org roles are **not** Spatie roles; they
are a module-owned `OrganizationRole` enum plus a permission matrix. A user is a
`Seller`/`SellerEmployee` at the platform level *and* holds an `OrganizationRole`
within each org they belong to.

| Role | Purpose |
|---|---|
| Owner | The company principal. Implicit-all. Exactly one. |
| Manager | Runs the org day-to-day; everything except ownership transfer and org deletion. |
| Finance | Bank account, payouts context, financial documents. |
| Warehouse | Fulfilment/inventory context (meaningful once Store/Order land). |
| Support | Reads org data to answer customer issues; no structural changes. |
| Marketing | Store presentation / campaigns context. |
| Editor | Edits catalogue-adjacent org content; no member or financial powers. |
| Viewer | Read-only. |

## 5.1 Permission matrix (capability × role)

Capabilities are org-scoped abilities checked by policies (not role names).
`●` = allowed, `—` = denied. Owner is implicit-all.

| Capability | Owner | Manager | Finance | Warehouse | Support | Marketing | Editor | Viewer |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `organization.view` | ● | ● | ● | ● | ● | ● | ● | ● |
| `organization.update` | ● | ● | — | — | — | — | — | — |
| `organization.manage_kyc` | ● | ● | — | — | — | — | — | — |
| `member.view` | ● | ● | ● | ● | ● | ● | ● | ● |
| `member.invite` | ● | ● | — | — | — | — | — | — |
| `member.update_role` | ● | ● | — | — | — | — | — | — |
| `member.remove` | ● | ● | — | — | — | — | — | — |
| `invitation.manage` | ● | ● | — | — | — | — | — | — |
| `bank_account.view` | ● | ● | ● | — | — | — | — | — |
| `bank_account.update` | ● | — | ● | — | — | — | — | — |
| `document.upload` | ● | ● | ● | — | — | — | ● | — |
| `store_request.create` | ● | ● | — | — | — | — | — | — |
| `store_request.cancel` | ● | ● | — | — | — | — | — | — |
| `settings.update` | ● | ● | — | — | — | — | — | — |
| `ownership.transfer` | ● | — | — | — | — | — | — | — |

The matrix lives in code (adding a capability is a code change → the matrix is an
enum-driven map, not a table). Org policies resolve a member's capabilities from
this matrix; the Owner short-circuits to allow.

---

# 6. Invitations

## 6.1 Operations

| Op | Actor | Effect |
|---|---|---|
| Invite | member with `member.invite` | creates Pending invitation, emails a hashed-token link |
| Accept | invitee (authenticated seller-guard user matching the email) | creates an Active `OrganizationMember`, invitation → Accepted |
| Reject | invitee | invitation → Rejected |
| Expire | system (scheduler) | past `expires_at` → Expired |
| Cancel | member with `invitation.manage` | Pending → Cancelled |
| Re-send | member with `invitation.manage` | invalidates the old token, issues a new one, re-emails |

## 6.2 Security rules

- The acceptance **token is hashed at rest**; the raw token is emailed only and
  never returned by any API (ADR-025).
- An invitation is bound to a **normalised email**; acceptance requires the
  authenticated user's email to match.
- Issuing/re-sending invalidates any prior Pending invitation for that
  `(organization, email)`.
- Expiry is enforced server-side; an expired token is indistinguishable from an
  invalid one in the response.
- A user already a member cannot be re-invited (idempotent guard).
- The invited role may not be `Owner` (ownership arrives only by transfer).

---

# 7. Store Opening Requests

## 7.1 Lifecycle

```
Draft ──submit──▶ Submitted ──(auto)──▶ Pending ──approve──▶ Approved
  │                    │                    │
cancel              cancel               reject
  ▼                    ▼                    ▼
Cancelled          Cancelled            Rejected
```

- **Draft** — seller is composing; not visible to admins.
- **Submitted / Pending** — in the admin queue (Submitted is the seller's act;
  Pending is the queue state; they may collapse to one in Organization-1).
- **Approved** — admin approved; `StoreOpeningApproved` fired; Store module
  creates the Store.
- **Rejected** — admin rejected with notes/reason.
- **Cancelled** — withdrawn by the org before a decision.

## 7.2 Fields

Requested store **name**, **slug**, **logo** (`HasMedia`), **category**,
**description**, seller **reason**, admin **notes**, plus the **approval history**
(who/when/outcome) — the approval history is the audit trail (§15), not a
duplicate table.

## 7.3 Enforcement

- Submission fails fast if `remainingStoreSlots() === 0` (limit reached).
- Approval performs the **authoritative** limit re-check (the plan/override may
  have changed while pending) before firing `StoreOpeningApproved`.

---

# 8. Events

All extend `App\Core\Domain\Events\BaseEvent` (correlation id, timestamp,
past-tense name). Carry a `guard`/actor-type value where a consumer must resolve
the concrete user subclass (the ADR-027 pattern).

| Event | Dispatched when | Notable consumers |
|---|---|---|
| `OrganizationCreated` | org registered (Pending) | Activity, Audit |
| `OrganizationApproved` | admin approves KYC | Notification (owner), Activity, Audit |
| `OrganizationRejected` | admin rejects KYC | Notification, Activity, Audit |
| `OrganizationSuspended` | admin suspends | Notification, Activity, Audit |
| `OrganizationRestored` | admin/owner restores | Activity, Audit |
| `OrganizationOwnerTransferred` | ownership transfer | Notification (both), Activity, Audit |
| `OrganizationMemberInvited` | invitation created | Notification (invitee), Activity |
| `OrganizationMemberJoined` | invitation accepted | Notification (owner), Activity, Audit |
| `OrganizationMemberRemoved` | member removed | Activity, Audit |
| `StoreOpeningRequested` | request submitted | Notification (admins), Activity, Audit |
| `StoreOpeningApproved` | admin approves request | **Store module (creates Store)**, Notification, Activity, Audit |
| `StoreOpeningRejected` | admin rejects request | Notification, Activity, Audit |

Organization **subscribes** to Identity's `UserDeleted` (to handle a member whose
user was removed) — a consumer-direction import of `Identity\Domain\Events`,
added to `LayeringTest` as a reviewed exception when built.

---

# 9. Policies

Extend `App\Core\Presentation\Policies\BasePolicy`; check **capabilities**, never
roles; Owner short-circuits; a super-admin bypasses via `BasePolicy::before()`.

| Policy | Guards | Key methods |
|---|---|---|
| `OrganizationPolicy` | seller (own org, membership-scoped) + admin | `viewAny`(admin), `view`, `update`, `manageKyc`, `approve`(admin), `reject`(admin), `suspend`(admin), `restore`(admin), `transferOwnership`(owner) |
| `OrganizationMemberPolicy` | seller | `viewAny`, `view`, `invite`, `updateRole`, `remove` — with the owner-removal guard |
| `OrganizationInvitationPolicy` | seller + invitee | `create`, `cancel`, `resend`, `accept`(invitee), `reject`(invitee) |
| `StoreOpeningRequestPolicy` | seller + admin | `viewAny`, `view`, `create`(limit-gated), `cancel`, `approve`(admin), `reject`(admin) |
| `OrganizationBankAccountPolicy` | seller | `view`, `update` (Finance/Owner) |
| `OrganizationDocumentPolicy` | seller + admin | `view`, `upload`, `review`(admin) |

`BasePolicy::owns()` must be overridden for the seller-facing policies to
"member of this organization" (default `false` denies everything, per the
platform surprise-list).

---

# 10. DTOs

`DTO` suffix, `App\Modules\Organization\Domain\DTOs` (ADR-021).

| DTO | For |
|---|---|
| `RegisterOrganizationDTO` | create org (owner, legal name, country, currency, initial KYC) |
| `UpdateOrganizationDTO` | patch org profile (name, slug, settings) + `reason`, `present[]` |
| `SubmitKycDTO` | KYC field set (tax no, MERSIS, tax office, trade registry, authorized person) |
| `ReviewOrganizationDTO` | admin approve/reject (`decision`, `reason`) |
| `SuspendOrganizationDTO` | admin (`reason`) |
| `InviteMemberDTO` | (email, role) |
| `AcceptInvitationDTO` | (token) |
| `UpdateMemberRoleDTO` | (member uuid, role, reason) |
| `TransferOwnershipDTO` | (target member uuid, demoted role, reason) |
| `UpsertBankAccountDTO` | (holder, iban, bank, currency) |
| `UploadOrganizationDocumentDTO` | (type, file) |
| `ReviewDocumentDTO` | admin (decision, notes) |
| `UpdateOrganizationSettingsDTO` | settings patch |
| `CreateStoreOpeningRequestDTO` | (store name, slug, category, description, reason, logo) |
| `ReviewStoreOpeningRequestDTO` | admin (decision, admin notes/reason) |
| `AdminUpdateStoreLimitDTO` | admin (plan/override, reason) |

Each `readonly`, PATCH-style DTOs carry a `present[]` list (the Identity
convention).

---

# 11. Repositories

Contracts in `Domain\Contracts`, implementations in
`Infrastructure\Repositories`; every read declares its `$with` eager loads
(strict mode throws on lazy loads).

| Contract | Implementation | Purpose |
|---|---|---|
| `OrganizationRepositoryContract` | `OrganizationRepository` | lookup by uuid/owner/slug, admin pagination + filters, store-count |
| `OrganizationMemberRepositoryContract` | `OrganizationMemberRepository` | membership lookup, role resolution, owner lookup |
| `OrganizationInvitationRepositoryContract` | `OrganizationInvitationRepository` | pending-by-email, token verification, expiry sweep |
| `OrganizationDocumentRepositoryContract` | `OrganizationDocumentRepository` | documents by org/type/status |
| `OrganizationBankAccountRepositoryContract` | `OrganizationBankAccountRepository` | the one account per org |
| `StoreOpeningRequestRepositoryContract` | `StoreOpeningRequestRepository` | request lifecycle, admin queue, per-org counts |
| `OrganizationPlanRepositoryContract` | `OrganizationPlanRepository` | active plans, default resolution |

All implement `App\Core\Domain\Contracts\RepositoryContract` and are bound in
`OrganizationServiceProvider`.

---

# 12. API

Envelope per ADR-009 (`{success, message?, data, meta?}` / error
`{success:false, code, message, errors?}`). UUIDs only. Money — n/a here — would
be decimal strings if it appeared. Rate limits: seller endpoints `throttle:api`;
admin endpoints `throttle:panel`; invitation-accept is `throttle:auth`-adjacent.

## 12.1 Seller API (`auth:sanctum`, seller guard, membership-scoped)

| Method | Path | Capability |
|---|---|---|
| POST | `/organization` | register (a seller with no org) |
| GET | `/organization` | `organization.view` (own) |
| PATCH | `/organization` | `organization.update` |
| POST | `/organization/kyc` | `organization.manage_kyc` |
| GET | `/organization/members` | `member.view` |
| POST | `/organization/members/invitations` | `member.invite` |
| DELETE | `/organization/members/invitations/{uuid}` | `invitation.manage` (cancel) |
| POST | `/organization/members/invitations/{uuid}/resend` | `invitation.manage` |
| PATCH | `/organization/members/{uuid}` | `member.update_role` |
| DELETE | `/organization/members/{uuid}` | `member.remove` (not the owner) |
| POST | `/organization/ownership/transfer` | `ownership.transfer` (owner) |
| GET/PUT | `/organization/bank-account` | `bank_account.view` / `.update` |
| GET/POST | `/organization/documents` | `document.upload` |
| GET/PATCH | `/organization/settings` | `settings.update` |
| GET | `/organization/store-requests` | `store_request.view` |
| POST | `/organization/store-requests` | `store_request.create` (limit-gated) |
| POST | `/organization/store-requests/{uuid}/cancel` | `store_request.cancel` |

## 12.2 Invitation acceptance (invitee, separate surface)

| Method | Path | Notes |
|---|---|---|
| POST | `/organization/invitations/{token}/accept` | authenticated seller-guard; email must match |
| POST | `/organization/invitations/{token}/reject` | |

## 12.3 Admin API (`throttle:panel`, admin guard, permission-gated)

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/organizations` | `organization.view_any` |
| GET | `/admin/organizations/{uuid}` | `organization.view` |
| POST | `/admin/organizations/{uuid}/approve` | `organization.approve` |
| POST | `/admin/organizations/{uuid}/reject` | `organization.reject` |
| POST | `/admin/organizations/{uuid}/suspend` | `organization.suspend` |
| POST | `/admin/organizations/{uuid}/restore` | `organization.restore` |
| PATCH | `/admin/organizations/{uuid}/store-limit` | `organization.manage_limit` |
| POST | `/admin/organizations/{uuid}/documents/{doc}/review` | `organization.review_documents` |
| GET | `/admin/store-requests` | `store_request.view_any` |
| POST | `/admin/store-requests/{uuid}/approve` | `store_request.approve` |
| POST | `/admin/store-requests/{uuid}/reject` | `store_request.reject` |

Permissions registered via `PermissionRegistry::resource('organization', …)` +
`::ability('organization.approve', [UserType::Admin])` etc. in
`OrganizationServiceProvider`, attached to roles in `RolePermissionSeeder`
(Admin/Super Admin; store-request review may also go to a dedicated reviewer
role).

---

# 13. Filament

**Registered explicitly per panel** (the isolation decision just applied to
Identity's `UserResource`): admin resources on the Admin panel only, seller
resources on the Seller panel only. **Strictly presentation** — every write
routes through the module's Actions/Services; no business logic in Filament.

## 13.1 Admin panel

- `OrganizationResource` — list (status/plan filters), view, **approval screen**
  (approve/reject/suspend/restore with reason), document review, store-limit
  override.
- `StoreOpeningRequestResource` — the **review queue**: list of Pending requests,
  view with requested details, **approve/reject actions** (reason/notes) that
  call the review action → dispatch the event.
- Widgets: pending-organizations count, pending-store-requests count.

## 13.2 Seller panel

- `OrganizationResource` — the seller's **own** org (single-record): profile,
  KYC status, settings.
- `StoreOpeningRequestResource` — create/track requests; status timeline.
- `BankAccountResource` / documents — Finance/Owner gated.

### Ekip — as built (post-freeze; Presentation only)

The spec's single `MemberResource` shipped as **two** resources under an **Ekip**
navigation group, both membership-scoped:

- `TeamMemberResource` — the roster. Invite (a header action, not a create page),
  change an org role, remove a member.
- `TeamInvitationResource` — invitations in flight: resend, withdraw.

**Why two and not one.** A membership row only exists once the invited person
accepts (ADR-031), so an invitation in flight is invisible on the members list.
Folded into one resource it would have been a second table with its own status
column, its own filters and its own actions inside a members screen. Split, each
list answers one question. **Cost:** two navigation entries where the spec
anticipated one.

**Why no relation manager on `OrganizationResource`.** Filament relation managers
need a declared Eloquent relation, and `Organization` has neither `members()` nor
`invitations()`. Adding them is a Domain change to a frozen module, and this was
a Presentation-only change — so the tenancy wall is the resources'
`getEloquentQuery()` (`organizationIdsForUser()`, ADR-030), exactly as the other
seller resources scope themselves, with `OrganizationMemberPolicy` /
`OrganizationInvitationPolicy` re-checking the capability per row. Two
independent walls, not one.

**Org roles only.** The assignable set is `OrganizationRole::assignable()` — the
§5.1 matrix minus Owner, since ownership is reached only by transfer (ADR-029).
No platform (Spatie) staff role is reachable from the seller panel; staff roles
are granted under **Personel** in the admin panel and nowhere else
(Identity §12.2). Conversely, the admin panel's seller area offers **no** team
controls at all — a merchant's team is the merchant's to manage.

**Change category: "explicitly required by a later module"** — an owner-approved
UX refinement. No Domain, Application or Infrastructure code was touched; every
write is `InviteMemberAction`, `ChangeMemberRoleAction`, `RemoveMemberAction`,
`ResendInvitationAction` or `CancelInvitationAction`.

## 13.3 Actions / pages

Approve, Reject, Suspend, Restore, Review-document, Transfer-ownership,
Invite-member, Approve/Reject-store-request — each a Filament action delegating
to the corresponding module Action, wrapped in `AuditContext::withReasonFor()` so
the operator's reason reaches the audit entry.

---

# 14. Notifications

Extend `App\Core\Application\Notifications\BaseNotification` (the Core platform
base — never the Notification module). Mail + database; security/decision notices
ignore opt-out. Locale-aware (recipient's language). EN + TR strings.

| Notification | To | Trigger |
|---|---|---|
| `OrganizationInvitationNotification` | invitee | member invited |
| `OrganizationApprovedNotification` | owner | KYC approved |
| `OrganizationRejectedNotification` | owner | KYC rejected (with reason) |
| `OrganizationSuspendedNotification` | owner | suspended (with reason) |
| `OwnershipTransferredNotification` | old + new owner | transfer |
| `StoreOpeningApprovedNotification` | requester + owner | request approved |
| `StoreOpeningRejectedNotification` | requester | request rejected (with notes) |
| `StoreOpeningRequestedNotification` | admins (permission-gated) | request submitted — routed via a `store_request.receive_alerts`-style permission, the Identity `security.receive_alerts` precedent |

---

# 15. Audit

Organization is a **core aggregate**; every state change is forensically
recorded (ADR-027, the Identity precedent).

- **`Auditable` on** `Organization`, `OrganizationMember`,
  `OrganizationBankAccount`, `StoreOpeningRequest` (before/after diffs).
- **Secret-excluded columns** never enter the trail: `iban`, national id,
  `token_hash`. (Global exclusion list extended.)
- **Reason required** on every admin action (approve, reject, suspend, restore,
  store-limit change, document review, request approve/reject) and on
  destructive member actions (remove, role change, ownership transfer). Carried
  via `AuditContext::withReasonFor()` into `metadata.reason`.
- **Event-sourced forensic entries** for changes that touch only excluded/secret
  columns or that have no model diff (e.g. an approval that flips `status` — that
  *does* diff, so the trait covers it; a bank-account change diffs non-secret
  columns and records that the IBAN changed *without* the value). Where a change
  is invisible to the diff (analogous to 2FA), the corresponding event drives the
  entry via an Audit subscription.
- **Every entry carries** actor, target, reason (when given), IP, user-agent,
  correlation id, timestamp — the correlation id stitches an approval → event →
  downstream Store-creation into one incident.

Audited via the same forensic store; **no separate mechanism** (the Identity
ruling).

---

# 16. Tests

Mirrors Identity's suite structure and rigor.

| Suite | Kind | Asserts |
|---|---|---|
| `OrganizationLifecycleTest` | Feature | register → approve/reject → suspend/restore/archive transitions |
| `OrganizationOwnershipTest` | Feature | owner invariant, transfer atomicity, owner-removal denial |
| `MembershipTest` | Feature | join, role change, remove; capability matrix enforcement |
| `InvitationTest` | Feature | invite/accept/reject/expire/cancel/resend; hashed token; email-match; single-use |
| `StoreOpeningRequestTest` | Feature | request lifecycle; **limit enforced at submit AND approve**; event fired on approval |
| `StoreLimitResolutionTest` | Unit | override → plan → default; unlimited (null); no DB |
| `KycTest` | Feature | document upload (private disk), admin review, approval gates operability |
| `BankAccountTest` | Feature | IBAN encrypted at rest, audit-excluded, last-4 only to non-owner |
| `OrganizationAuthorizationTest` | Feature/Policy | every endpoint permission-gated; super-admin guard; membership scoping; capability matrix |
| `OrganizationAuditTest` | Feature | approvals/rejections/suspensions write forensic entries with reason + actor; secrets excluded |
| `OrganizationRepositoryTest` | Feature | contracts implemented; `$with` present; type/ownership scoping |
| `OrganizationArchitectureTest` | Arch | DTO suffix; no cross-module imports except permitted; enums final/backed; policies check capabilities |
| `OrganizationSecurityTest` | Security | invitation-token non-disclosure; IBAN non-disclosure; no privilege escalation via role change or transfer; store creation impossible without approval |

Unit tests have **no database** (the platform rule) — `StoreLimitResolutionTest`
and the permission-matrix resolver are unit; everything touching rows is Feature.
Feature tests touching locale seed Localization (`$this->seedPlatform()`);
authorization tests seed roles/permissions (`$this->seedAll()`).

---

# 17. Sprint Plan

Ordered by dependency; each phase independently reviewable and approved before
the next, exactly as Identity ran.

### Phase 0 — Prerequisites & scaffold *(blocks everything)* — ✅ **DONE**

- **Objectives:** ~~ratify ADR-028~~ ✅ (record + amendment log);
  ~~resolve the `BaseNotification` boundary~~ ✅ (moved to
  `App\Core\Application\Notifications\BaseNotification`; no exception, no
  weakening); scaffold the module directory, service provider, `LayeringTest`
  entry for Organization *(scaffold happens at the start of Phase 1)*.
- **Files:** `Architecture_Decision_Record.md`, `001_Architecture.md`
  (amendment log), `OrganizationServiceProvider`, `app/Modules/Organization/**`
  skeleton, `LayeringTest` additions, `docs/modules/README.md` index entry.
- **Risks:** the notification-base move touches Identity (frozen — permitted as a
  "change required by a later module"); must not alter Identity behaviour.
- **Dependencies:** none.
- **Acceptance:** ADR-028 ratified; layering green in principle; empty module
  boots; no behavioural change to Identity.

### Phase 1 — Organization core + lifecycle — ✅ **DONE**

- **Objectives:** `Organization`, `OrganizationStatus`, `OrganizationPlan`
  (lookup + seeder), register + status transitions, store-limit resolution.
  *Delivered: models, enum, plan lookup + `OrganizationPlanSeeder`, migrations,
  `RegisterOrganizationAction` + Approve/Reject/Suspend/Restore (audited with
  reason), repositories + contracts, partial `OrganizationPolicy`, service
  provider + permissions, `StoreLimitResolutionTest` (unit) +
  `OrganizationLifecycleTest`. Un-suspend is the `reinstate` ability, distinct
  from the soft-delete `restore` verb.*
- **Files:** model, enum, plan model/table/seeder, migrations,
  `RegisterOrganizationAction`, lifecycle actions, `OrganizationRepository`,
  `OrganizationPolicy` (partial), `OrganizationLifecycleTest`,
  `StoreLimitResolutionTest`.
- **Risks:** status-vs-soft-delete-vs-archive confusion; nail the semantics
  early.
- **Dependencies:** Phase 0; Localization (countries/currencies).
- **Acceptance:** an org registers Pending; admin approve/reject/suspend/restore
  works and is audited; limits resolve correctly; unit limit test passes.

### Phase 2 — Membership + ownership — ✅ **DONE** (ADR-029, ADR-030)

- **Objectives:** `OrganizationMember`, `OrganizationRole` + matrix, owner
  invariant, transfer, role changes, removal.
  *Delivered: `OrganizationCapability` + `OrganizationRole` (capability matrix) +
  `OrganizationMemberStatus` enums; `OrganizationMember` model (Auditable) +
  migration + factory; member repository + contract (incl. the ADR-030 isolation
  lookups); owner membership created on registration; `ChangeMemberRoleAction`,
  `RemoveMemberAction` (owner-removal refused), `TransferOwnershipAction`
  (atomic); `OwnershipViolation` domain exception; member/ownership events;
  `OrganizationMemberPolicy` (capability + tenancy isolation) + `OrganizationPolicy::owns()`
  widened to active membership; `CapabilityMatrixTest` (unit), `MembershipTest`,
  `OrganizationOwnershipTest`. Note: "Seller Employee cannot own" is a role
  distinction (both are type=seller) deferred to seller-role wiring in Phase 6;
  the type-level gate is enforced now.*
- **Files:** models/enums, `TransferOwnershipAction`, member actions,
  `OrganizationMemberPolicy`, capability resolver, `MembershipTest`,
  `OrganizationOwnershipTest`.
- **Risks:** the owner invariant (three-level enforcement); transfer atomicity.
- **Dependencies:** Phase 1.
- **Acceptance:** every capability-matrix branch enforced; owner cannot be
  orphaned; transfer atomic and audited.

### Phase 3 — Invitations — ✅ **DONE** (ADR-031)

- **Objectives:** invite/accept/reject/expire/cancel/resend; hashed token
  out-of-band; expiry sweep.
  *Delivered: the invitation mechanism is now **Core infrastructure** (ADR-031) —
  `App\Shared\Enums\InvitationStatus`, `App\Core\Domain\Contracts\InvitationTokenizerContract`
  + `Sha256InvitationTokenizer`, `App\Core\Domain\Concerns\HasInvitationLifecycle`,
  bound in `AppServiceProvider` — reusable by any future module. Organization
  consumes it: `OrganizationInvitation` model + migration + factory + repository;
  `InviteMemberAction` (hash stored, raw emailed once, prior pending cancelled,
  Owner role refused), `AcceptInvitationAction` (auth account required, email
  must match, never creates a user, single-use), Reject/Cancel/Resend (token
  rotation); `OrganizationInvitationNotification` (mail-only); `InvitationException`;
  `OrganizationInvitationPolicy` (manage = capability, respond = recipient email
  match); `OrganizationMemberInvited` event; `InvitationTest`. Expiry is enforced
  on access (`isAcceptable`); a scheduled sweep to flip stale rows to Expired is
  a small follow-up.*
- **Files:** `OrganizationInvitation`, status enum, actions, notification,
  `OrganizationInvitationPolicy`, scheduler entry, `InvitationTest`.
- **Risks:** token disclosure; email-match bypass; single-use.
- **Dependencies:** Phase 2; `BaseNotification` resolved (Phase 0).
- **Acceptance:** ADR-025-compliant token handling; all invitation transitions;
  security assertions pass.

### Phase 4 — KYC + documents + bank account — ✅ **DONE**

- **Objectives:** KYC fields, `OrganizationDocument` (private disk via
  `HasMedia`), admin document review, `OrganizationBankAccount` (encrypted IBAN,
  audit-excluded).
  *Delivered: `OrganizationKyc` (country-agnostic core columns + `metadata`
  jsonb for country-specific fields like MERSİS; national id encrypted +
  audit-excluded) + `SubmitKycAction`; `OrganizationDocument` (Auditable, files
  on the private-disk `documents` media collection, signed `temporaryUrl()`) +
  `OrganizationDocumentType`/`Status` enums + `UploadDocumentAction` +
  `ReviewDocumentAction` (admin, audited with reason) + `OrganizationDocumentReviewed`
  event; `OrganizationBankAccount` (Auditable, `encrypted` IBAN cast +
  `$auditExclude`, masked accessor) + `UpsertBankAccountAction` (changing the
  IBAN resets verification); repositories + contracts for documents and bank
  account; `OrganizationDocumentPolicy` (view = membership, review = admin
  permission), `OrganizationBankAccountPolicy` (capability + isolation);
  `organization.review_documents` permission; `KycTest` + `BankAccountTest`.
  Org KYC/document/bank relations added to the Organization model.*
- **Files:** models/enums, encryption casts, actions, policies, `KycTest`,
  `BankAccountTest`.
- **Risks:** encryption + audit-exclusion correctness; signed-URL scoping.
- **Dependencies:** Phase 1; Media (`HasMedia` trait).
- **Acceptance:** documents private + signed; IBAN encrypted and never in audit;
  approval gates operability.

### Phase 5 — Store Opening Requests (ADR-028) — ✅ **DONE**

- **Objectives:** `StoreOpeningRequest` lifecycle, submission limit fail-fast,
  authoritative approval re-check, `StoreOpeningApproved/Rejected` events.
  *Delivered: `StoreOpeningRequestStatus` (Draft → Pending → Approved/Rejected,
  or Cancelled — Submitted collapsed into Pending per §7.1); `StoreOpeningRequest`
  model (Auditable, HasMedia logo, `created_store_uuid` as a bare UUID ref, never
  a FK) + migration + factory + repository; Create/Submit/Cancel/Approve/Reject
  actions; `StoreOpeningException`; `StoreOpeningRequested`/`Approved`/`Rejected`
  events; `StoreOpeningRequestPolicy` (seller capabilities + admin permissions);
  `store_request.approve`/`reject` permissions; `StoreOpeningRequestTest`.*
  **A Store is never created here** — approval flips status + fires
  `StoreOpeningApproved`; the future Store module creates the store. **Limit
  chain preserved**: `currentStoreCount()` now counts approved requests, so
  override → plan → config binds fail-fast at submit and authoritatively at
  approval.
  **Two refinements applied on request:** document reviews gained a
  revision-friendly `NeedsRevision` outcome (vs terminal rejection); the bank
  account schema is now multi-account-friendly (`is_primary`, `label`, partial
  unique on one live primary per org) while the app enables exactly one.
- **Files:** model, status enum, actions, `StoreOpeningRequestPolicy`, events,
  notifications, `StoreOpeningRequestTest`.
- **Risks:** the limit race (submit-time vs approve-time); event contract for the
  future Store module.
- **Dependencies:** Phases 1–2.
- **Acceptance:** no Store is ever created here; approval fires the event under
  the limit; over-limit approval blocked.

### Phase 6 — Seller & Admin API — ✅ **DONE**

- **Objectives:** every endpoint in §12, ADR-009 envelopes, permission +
  membership scoping.
  *Delivered: seller controllers (Organization, Member, BankAccount, Document,
  StoreRequest) + the invitee Invitation controller; admin controllers
  (Organization, StoreRequest); FormRequests; 6 resources (IBAN masked, token
  never exposed); thin `UpdateOrganizationAction` + `SetStoreLimitAction`;
  capability methods on `OrganizationPolicy`; routes; `OrganizationAuthorizationTest`.*
  **Deviation from §12.1 (recorded):** endpoints are **plural + org-in-path**
  (`/organizations/{organization}/…`) because **ADR-030** lets a user belong to
  several organizations — the spec's singular `/organization` predated ADR-030.
  **Deferred:** `OrganizationSettings` (§2.6) was never built in the domain
  phases; its endpoints are omitted rather than introduce a new domain concept
  in presentation work. A signed document-download endpoint is a small follow-up
  (the resource exposes metadata + `has_file`; the model has `temporaryUrl()`).
- **Files:** controllers (seller + admin), FormRequests, resources, routes,
  `OrganizationAuthorizationTest`.
- **Risks:** membership scoping IDOR; admin/seller surface separation.
- **Dependencies:** Phases 1–5.
- **Acceptance:** all endpoints authorised and enveloped; authorization suite
  green.

### Phase 7 — Filament (admin + seller) — ✅ **DONE**

- **Objectives:** admin `OrganizationResource` + `StoreOpeningRequestResource`
  (approval screens); seller resources; **explicit per-panel registration**.
  *Delivered: admin `OrganizationResource` (approve/reject/suspend/reinstate row
  actions + document context) and `StoreOpeningRequestResource` (approve/reject
  queue) registered explicitly on the admin panel; a read-only, membership-scoped
  seller `OrganizationResource` (under a `Seller` subnamespace) on the seller
  panel. Every action delegates to the module Actions and is policy-gated — no
  business logic in Filament.*

### Phase 8 — Audit, events, hardening — ✅ **DONE**

- **Objectives:** confirm every §15 audit path, Audit/Activity subscriptions,
  correlation across approval → event → (future) Store creation.
  *Delivered: `OrganizationArchitectureTest` (repos↔contracts, Domain purity,
  final actions, enums) + `OrganizationSecurityTest` (IBAN never disclosed,
  invitation token never disclosed, cross-org isolation, no store without
  approval, authoritative limit gate). Every aggregate is `Auditable`, so the
  forensic trail is complete and the global event subscriber logs each domain
  event to the audit channel.*
  **Deferred (documented, non-security):** the Activity user-timeline listener
  for Organization events — the forensic Audit record is complete; wiring the
  narrative would modify the Activity module's exhaustive enum and is additive.

---

# 18. Acceptance criteria (module complete)

- All lifecycle, membership, invitation, KYC, bank, and store-request flows work
  and are policy-guarded + audited with reasons.
- **No Store is ever created without an approved request** (ADR-028) — asserted.
- Store limits resolve override → plan → default, unlimited supported.
- Owner invariant holds; ownership transfer is atomic.
- Secrets (IBAN, national id, tokens) encrypted and audit-excluded; tokens
  out-of-band only.
- Every response uses the ADR-009 envelope; UUIDs only.
- Filament resources are per-panel isolated and delegate to Actions.
- The two open items (ADR-028 ratified, `BaseNotification` relocated/excepted)
  are closed.
- `make check` passes.

---

# 19. Reading order for the implementer

1. This document, §0 (ADR-028) and the open-items box first.
2. `docs/modules/Identity.md` — the pattern and rigor bar this module matches.
3. `docs/Architecture_Decision_Record.md` (esp. ADR-025, ADR-027) and
   `001_Architecture.md`.
4. `docs/audit.md` before writing any `Auditable` model.
5. `app/Modules/Identity` as the reference implementation (repositories, actions,
   policies, admin surface, Filament) — then build Organization to match.
