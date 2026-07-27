# Work order — admin organization review surface (documents, KYC, bank)

**Disposable. Delete when done.** For the server-side Claude session (has `vendor/`,
can run the app and test Filament live). Build incrementally, keep the suite green
(currently 422 passed / 0 failed), commit per piece, push.

## Problem
An admin approving an organization cannot review what the seller submitted. The admin
`OrganizationResource` (`app/Modules/Organization/Presentation/Filament/Resources/
OrganizationResource*`) has only List + View + Approve/Reject actions — no view of the
uploaded **documents**, no **KYC** detail, no **bank** detail. The admin is asked to
approve/reject blind. The domain already supports per-document review
(`App\Modules\Organization\Application\Actions\ReviewDocumentAction` — approve / request
a revision / reject); it is just not surfaced in the admin panel. Surface it.

## Hard rules
- **Presentation only.** Do NOT change `Domain/`, `app/Models`, or any frozen module
  logic. Call the EXISTING actions:
  - `ReviewDocumentAction` (approve / request-revision / reject a document — read its
    `handle()` for the exact arguments/decision enum + optional reason).
  - Keep the existing `ApproveOrganizationAction` / `RejectOrganizationAction` wiring.
- **Admin panel + permission-gated.** This is the `admin` guard. Gate the review
  actions on the same permission the existing approve/reject uses (trace the admin
  `OrganizationResource` / the admin API `AdminOrganizationController` +
  `DocumentController` for the permission names). A non-admin must never reach it.
- **Private documents stay private.** Documents live on the private media disk
  (`config('marketplace.media.private_disk')`, via the `HasMedia` trait / Spatie
  `documents` collection). On this test server that disk is now `local`, whose driver
  does NOT support `temporaryUrl()`. So DO NOT view documents through a signed
  `temporaryUrl` — that throws on local. Instead add an **authorized streaming
  download**: a Filament action (or a thin admin-guarded route) that checks the admin
  permission and returns `Storage::disk($media->disk)->download($media->getPathRelativeToRoot())`
  (or `$media->toResponse()` / a `response()->streamDownload`). This works on any disk
  (local now, s3 later) and keeps access admin-only. Never expose a public URL.
- Follow conventions: `declare(strict_types=1)`, admin-panel red theme, tr/en lang
  strings, per-panel resource (no shared discovery).

## Build (on the admin `OrganizationResource`)

### 1. Review detail (infolist on the View page)
Show, read-only, what the admin needs to decide:
- Company: legal name, display name, status, country, currency, owner.
- **KYC**: submitted? national id **masked** (last 4 only — it is encrypted and
  audit-excluded, never show it in full), KYC status/date.
- **Bank account**: **masked** IBAN (last 4), bank name, holder — for payout sanity.
Mask the encrypted fields; the point is verification, not exposure.

### 2. Documents — view + review (relation manager or infolist section)
List the organization's uploaded documents with: type/label, status (pending /
approved / needs-revision / rejected), uploaded-at. Per row:
- **View / Download** → the authorized streaming download described above (works on the
  local disk).
- **Review actions** → **Approve**, **Request revision** (with a reason field), **Reject**
  (with a reason) — each calls `ReviewDocumentAction`. Reflect the new status in the row.

### 3. Keep org Approve/Reject
Leave the existing organization Approve/Reject actions; ideally surface a hint that
pending documents exist so an admin does not approve an org with unreviewed docs (a
soft nudge, not a hard block unless the domain already enforces it — do not add new
domain rules).

## Tests
Feature/Livewire tests under `tests/`: an admin can see the org's documents, download
one, and approve / request-revision / reject it (asserting the domain status changed via
`ReviewDocumentAction`); a non-admin (or wrong guard) is denied; masked fields never
render the full encrypted value. Keep the whole suite green.

## Live end-to-end verify (browser)
As an admin at `/admin`: open the pending organization the seller just submitted, see
its KYC + bank (masked) + the uploaded document, **download/open the document**,
**approve** it, then **approve the organization**. Confirm no S3/credentials error and
no 500. Then as the seller, confirm the document/org status reflects the review.

## Finish
- Commit per piece, push `origin main`.
- `php artisan test` → 0 failed.
- `git rm BUILD_ADMIN_REVIEW.md`, commit, push.
- Report the final `Tests:` line and what each piece calls.
