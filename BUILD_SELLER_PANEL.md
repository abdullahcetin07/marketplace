# Work order — build the seller self-service onboarding UI (Filament seller panel)

**Disposable. Delete when done.** For the server-side Claude session (has `vendor/`,
can run the app and test Filament live). Build incrementally — one screen per commit,
keep the suite green (currently 384 passed / 0 failed), verify each in the browser.

## Context / the gap
The full seller onboarding flow already exists as **domain actions + a seller API**
(routes/api.php `organizations` group). What is missing is only the **Filament seller
panel UI** — today it is read-only: `OrganizationResource` (Seller) has List + View,
`StoreResource` (Seller) has List. There is NO UI to create an organization, submit
KYC, upload documents, set a bank account, or request a store. Build those screens.

## Hard rules
- **Frozen domain & models.** Do NOT change anything under `Domain/` or `app/Models`,
  and do NOT reimplement business logic. Every form/action CALLS an existing
  Application action (listed below). The seller API controllers already call these —
  read them as the source of truth for fields, DTOs, request validation, and
  authorization:
  - `app/Modules/Organization/Presentation/Controllers/Api/OrganizationController.php`
    (`store` → create, `update`, `submitKyc`)
  - `.../Api/BankAccountController.php` (`update` → upsert)
  - `.../Api/DocumentController.php` (`store` → upload, `index`)
  - `.../Api/StoreRequestController.php` (`store`, `submit`, `cancel`, `index`)
  Reuse each controller's FormRequest / DTO where practical, or mirror its validation.
- **Actions to call** (never inline their logic):
  `RegisterOrganizationAction`, `UpdateOrganizationAction`, `SubmitKycAction`,
  `UpsertBankAccountAction`, `UploadDocumentAction`, `CreateStoreOpeningRequestAction`,
  `SubmitStoreOpeningRequestAction`, `CancelStoreOpeningRequestAction`
  (all in `app/Modules/Organization/Application/Actions`).
- **Membership scoping (ADR-030).** A seller may belong to several organizations, and
  must only ever see/act on their own. Scope every query and every action through the
  actor's membership; reuse the existing policies / `OrganizationAuthorizationContract`
  / the seller API's authorization. A member of another org must be denied by
  construction — mirror what the API controllers already enforce. Do NOT invent a new
  tenancy model; keep it per-resource membership scoping like the API.
- **Encrypted / sensitive fields.** National id (KYC) and IBAN (bank) are encrypted by
  model casts — pass plaintext to the action, let the cast encrypt; never double-encrypt.
  On display, mask them (e.g. last 4) or omit; they are audit-excluded for a reason.
- **Documents live on the PRIVATE disk.** Use Filament `FileUpload` bound to the private
  disk (as `UploadDocumentAction` expects), status starts `pending`. View via a
  temporary/signed URL, never a public one.
- **Per-panel resources, never discovered from a shared root** (see the panel providers'
  existing comments). Register seller resources explicitly in `SellerPanelProvider`.
- Follow project conventions: README for any new structural dir, `declare(strict_types=1)`,
  seller-panel amber theme, navigation groups already defined
  (`nav.catalogue` / `nav.orders` / `nav.store`).

## Screens to build (incremental — commit + verify each)

### 1. Organization — create & edit
On the existing `OrganizationResource` (Seller):
- Add a **Create** page (fields per `RegisterOrganizationAction` / the API `store`
  request: legal_name, display_name, country, currency, …). On submit call
  `RegisterOrganizationAction` with the current seller as owner.
- Add an **Edit** page → `UpdateOrganizationAction`.
- The List should show only the seller's own organizations (already View-only — extend).
- After a seller with no organization logs in, this is their first stop. Consider a
  clear empty-state CTA ("Şirketini oluştur").

### 2. KYC
A page or form section under the organization (e.g. a `Pages/SubmitKyc` on the
resource, or an Edit-page section) → `SubmitKycAction`. Country-agnostic; national id
encrypted. Show current KYC status.

### 3. Documents (upload + list)
A **Relation Manager** on the organization (documents) →
- `FileUpload` on the private disk → `UploadDocumentAction` (status `pending`).
- Table listing the org's documents with their status (pending / approved /
  needs-revision) and a secure view link.

### 4. Bank account
A page/form → `UpsertBankAccountAction` (IBAN encrypted, primary account). Show masked
IBAN when one exists.

### 5. Store Opening Requests (the core of the flow)
A **seller** `StoreOpeningRequestResource` (new, under
`app/Modules/Organization/Presentation/Filament/Seller/Resources/` — the existing
`StoreOpeningRequestResource` lives in the ADMIN Filament namespace for approval, do
NOT reuse it directly) OR a relation manager on the organization:
- **Create** → `CreateStoreOpeningRequestAction` (draft).
- **Submit** action → `SubmitStoreOpeningRequestAction` (moves to pending admin review).
- **Cancel** action → `CancelStoreOpeningRequestAction`.
- List with status; make it obvious that an approved request is what produces a Store
  (the admin approves in the admin panel; a `Store` then appears in `StoreResource`).

## Tests
For each screen, add a Filament/Livewire feature test under `tests/` (mirror the style
of `tests/Feature/Auth/SellerRegistrationTest.php`: set the seller panel, act as a
seller, fill the form, call the action, assert the domain state changed AND that a
seller from another org is denied). Cover at least: create org, submit KYC, upload a
(non-empty) document, upsert bank account, create + submit a store request. Keep the
whole suite green.

## Live end-to-end verification (browser)
Register a seller at `/seller/register` → verify email (or mark verified) → in `/seller`:
create organization → submit KYC → upload a document → add bank account → create &
submit a store-opening request. Then in `/admin`, open StoreOpeningRequestResource,
**approve** it, and confirm a `Store` is created and now shows in the seller's
`StoreResource`. No 500s; membership scoping holds (a second seller can't see the first
seller's org/request).

## Finish
- One commit per screen (clear messages), push `origin main` after each or in a batch.
- Add/refresh READMEs for any new Filament dirs.
- `php artisan test` → 0 failed.
- `git rm BUILD_SELLER_PANEL.md`, commit, push.
- Report: the final `Tests:` line, and a short list of what each screen calls.
