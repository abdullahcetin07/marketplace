# Work order — post-Offer live-test refinements

**Disposable. `git rm` when done.** For the server-side session. Owner-approved
2026-07-29 during live testing. The authorizing docs are **already written owner-side**
(ADR-047 + amendment log + Catalog §3.2 + the Organization/Store freeze-notice
exceptions) — do **not** re-author them; if any are missing after `git pull`, STOP and
report (ADR-018). Build **one commit per lettered group**, keep the suite green
(`php artisan test`), human pushes.

**Guardrails (unchanged, still enforced):** `declare(strict_types=1)`; money = integer
minor units; UUID public / internal id never leaves; roles by name; policies check
permissions; DTOs `...DTO` in `Domain/DTOs/`; side effects in `after()`; no `dd/dump`;
`LayeringTest` green (no cross-module imports — read other modules via Core contracts).
Two frozen modules are touched **only within the owner-approved exceptions below** —
nothing else in Organization/Store changes.

---

## A — Catalog: `accepts_products` (ADR-047, amends ADR-038)
Replace the leaf-only attach rule with a per-category **`accepts_products`** boolean.
1. Migration: add `accepts_products` (boolean, default false) to `categories`; **data
   migration** sets it `true` for every current leaf (no children), `false` otherwise —
   so existing products keep validating.
2. Attach validation: swap the `categoryIsNotALeaf` check for
   `! category.accepts_products` (keep the exception class but rename/repurpose to
   `categoryDoesNotAcceptProducts`, reason `category_does_not_accept_products`). A
   flagged category may have children — that is the point.
3. Category Manager UI (admin CategoryResource): a toggle for `accepts_products`. Guard:
   turning it **off** on a category that already has products is blocked (clear error).
4. Tests: a product attaches to a flagged mid-level category (Makyaj with a child); an
   unflagged container (Kozmetik) refuses; existing leaf behaviour intact after migration.

## B — Catalog: delete empty categories
A category is deletable **only when it has no products AND no children** (a mis-created
one). Add a `DeleteCategoryAction` + the admin delete action, guarded by both checks
(reuse `categoryHasActiveChildren`; add a has-products check). Deleting a non-empty
category is refused with a clear message. Test both guards.

## C — Catalog admin: Brand logo upload
The Brand list shows a logo but the **create/edit form has no upload field**. Add a
`FileUpload` (image) to the seller/admin BrandResource form, writing through the same
media path the module already uses (`HasMedia`, the disk from
`config('filament.default_filesystem_disk')` — mirror how the product image action stages
+ moves, so a staged relative path is handled, not a raw `addMedia(string)`). Test that
creating a brand with a logo persists the media.

## D — Offer: buy-box columns on "Tekliflerim"
On the seller offers list add two **computed, read-only** columns (buy box is computed,
never stored — ADR-045):
1. **Buy-box sırası** — this offer's rank among the variant's eligible (Active + in-stock)
   offers, ascending by price (ties by `created_at`); show "—" if the offer is not
   eligible (paused/out-of-stock/suspended).
2. **Buy-box fiyatı** — the current winning (lowest eligible) price for that variant, as a
   decimal string, so the seller sees what to beat.
Use the existing `OfferQueryContract`/repository; no new stored state. **Commission is NOT
added** (deferred to Payment/Finance, ADR-042 §0.2 — no source of truth yet). Test rank +
winning-price for a 3-offer variant.

## E — Organization (FROZEN — owner-approved exception; see the freeze note)
**Presentation + one action only; Domain otherwise untouched.**
1. **Team member edit role** — expose an Edit action on `TeamMemberResource` calling the
   existing `ChangeMemberRoleAction` (org roles only: Manager / Seller Employee — never
   staff roles; Owner excluded per ADR-029).
2. **Deactivate / reactivate a member** — add `ChangeMemberStatusAction`
   (Domain/Application) toggling the member's existing `status` (active ⇄ inactive),
   with its event; expose it on `TeamMemberResource`. **Keep Remove.** Membership-scoped
   via `organizationIdsForUser()`; the Owner can never be deactivated/removed (ADR-029).
3. Tests: role change; deactivate then reactivate; Owner is protected; a second seller
   cannot touch the first's members.

## F — Organization + Store (FROZEN — owner-approved): onboarding reflow
**ADR-028 preserved — a store is created only by `StoreOpeningApproved`.** This is a
Presentation reflow reusing existing actions.
1. Seller **"Yeni Organizasyon"** (`CreateOrganization`) gains a **required** "Mağaza
   bilgileri" section: store name, slug, category, description (the
   `CreateStoreOpeningRequestDTO` fields). On submit: create the Organization, then create
   its Store Opening Request via `CreateStoreOpeningRequestAction`. One seller step.
2. **Store name uniqueness (Store, frozen — owner-approved):** add
   `StoreQueryContract::storeNameExists(string $name): bool` (+ Store impl) and validate
   the proposed name at request time (reject a taken name with a clear message, in both the
   onboarding form and the "Yeni Mağaza Talep Et" form). Add a **DB unique index** on the
   store name for integrity. Consider pending SOR names too, so two pending requests can't
   both claim a name.
3. Remove the standalone **StoreOpeningRequest create page** from the seller nav
   (`shouldRegisterNavigation() => false` or drop the Create page), but keep a **read-only
   status** view (pending / approved / rejected) for the seller.
4. **"Yeni Mağaza Talep Et"** on the seller **StoreResource** ("Mağazalarım") list — a
   header action opening the SOR create form (the relocated entry point) for an additional
   store. (Store Presentation change — owner-approved.)
5. Admin approval flow unchanged: approving the SOR creates the store, which then appears
   active in the seller "Mağazalarım" list.
6. Tests: creating an org with store info produces a pending SOR; a duplicate store name is
   rejected; admin approval yields an active store in the list; the standalone SOR create
   page is gone from the seller nav but reachable from Stores.

---

## Finish
Update the touched module docs' "as built" notes to match what shipped (Catalog §3.2 is
already updated; add short as-built lines to Organization.md/Store.md if the surfaces
differ from the freeze-note description). `git rm BUILD_REFINEMENTS.md`, commit. Report
the `php artisan test` count and a short live-verify note (or drive Livewire if no
browser). If anything here conflicts with the docs chain, STOP and report (ADR-018).
