# Work order — split user management by actor type + tenancy

**Disposable. Delete when done.** For the server-side session (has `vendor/`, tests +
app). Owner-approved UX refinement (Presentation only — the frozen Identity/Organization
DOMAINS are NOT touched; only their Filament surfaces). Build incrementally, one commit
per area, keep the suite green (currently ~433 + the media test), push (human pushes).

## The decision (approved by the product owner)
Today the admin panel's single `UserResource` lists ALL users (admin + seller + customer)
in one place, and there is no team management in the seller panel. Change it to:

- **Admin panel** — three SEPARATE areas under a "Kullanıcılar" nav group, so there is no
  clutter and role-granting is scoped to the right context:
  1. **Personel (Staff)** — the platform's own team (Admin-type users). The admin **creates**
     staff and **assigns staff roles** and suspends them. This is "my team".
  2. **Satıcılar (Sellers)** — seller-type users, **oversight/support ONLY**: view, org
     memberships, login history / security (forensic), suspend / reinstate. **No role
     assignment, no team management** (a seller's team is the seller's to manage).
  3. **Müşteriler (Customers)** — customer-type users, oversight/support only: view,
     suspend/reinstate, security.
- **Seller panel** — a new **Ekip (Team)** area where a seller manages their OWN
  organization's members: invite, assign an org role (Manager / Seller Employee), change
  role, remove, resend/cancel invitation — scoped to their own org (never another's).

Role-granting is split by context: **staff roles → admin (to staff); org roles → seller
(to their team).** Admins never grant staff roles to sellers/customers, and never manage a
seller's team.

## Hard rules
- **Presentation only.** Do NOT change `Domain/`, `app/Models`, or module Application
  logic. Reuse the EXISTING actions/policies:
  - Managing/suspending a user, assigning roles: `AdminUpdateUserAction` +
    `AdminUpdateUserDTO`, gated by `UserPolicy` (`update`, `assignRoles`).
  - **Respect the privilege-escalation guard** already in `UserPolicy` (a non-super-admin
    cannot create, modify, or grant Super Admin — see `UserPolicy` ~line 161,
    `cannot_modify_super_admin`). Staff creation must obey it: an Admin may create/grant up
    to their own level, never Super Admin.
  - Seller team: `InviteMemberAction`, `ChangeMemberRoleAction`, `RemoveMemberAction`,
    `ResendInvitationAction`, `CancelInvitationAction` (all exist).
  - Staff password policy: `StrongPassword::staff()`.
- Roles by NAME via `config('marketplace.roles.*')` — staff roles are `super_admin`,
  `admin`, `editor`, `category_manager`, `support`, `finance`; org roles are the
  Organization module's (Owner / Manager / Seller Employee). Never by id.
- Per-panel resources, registered explicitly; scope every query.
- `declare(strict_types=1)`, no `dd/dump`, tests green, etc.

## Build

### Admin panel (Identity Presentation — replaces the single UserResource)
Split `app/Modules/Identity/Presentation/Filament/Resources/UserResource.php` into three
type-scoped resources under one "Kullanıcılar" navigation group. Each overrides
`getEloquentQuery()` to `where('type', …)` for exactly its actor type.

1. **StaffResource** (type = Admin):
   - List + View (profile, roles, status, last login).
   - **Create** a staff user: first_name / last_name (nullable, ADR-012) / email /
     password (`StrongPassword::staff()`) + one or more **staff roles**. Mirror the
     `marketplace:create-admin` command's creation (Admin::create + `assignRole`, locale
     defaults, `email_verified_at` set or an invite — see note) and the seller Register
     page's Presentation-create precedent. **Enforce the escalation guard**: the role
     options offered must exclude Super Admin unless the actor is Super Admin; block
     creating/granting a role above the actor's level via `UserPolicy`.
   - Edit: change staff roles, suspend / reinstate (via `AdminUpdateUserAction`).
   - Gate on a staff-management permission (Super Admin / Admin).
   - Password note: mail is not configured on the test box, so for now the admin sets an
     initial `StrongPassword::staff()` password (as the CLI does). A "send a set-password
     invitation" flow is a documented follow-up (there is Core invitation infra, ADR-031,
     but it is org-scoped today — do not force it here).

2. **SellerResource** (type = Seller) — **oversight only**:
   - List + View: profile, the seller's organization memberships (read-only), login
     history / the forensic security timeline, current status.
   - Actions: suspend / reinstate ONLY (via `AdminUpdateUserAction`). **No role
     assignment, no team management, no create.**
   - Gate on a seller-oversight/support permission (Admin / Support).

3. **CustomerResource** (type = Customer) — oversight only:
   - List + View + suspend/reinstate + security. No role/team management, no create.
   - Gate on a customer-oversight/support permission.

Remove the old all-users `UserResource` (or repurpose it as one of the three). Make sure
nothing else references the removed class.

### Seller panel (Organization Presentation) — Team management
Add a **Members/Team** surface on the seller `OrganizationResource` (a
`MembersRelationManager`, or a dedicated resource) showing the org's members + pending
invitations:
- Invite (email + org role), change an org role, remove a member, resend / cancel an
  invitation — each calling the existing Organization actions above.
- **Membership-scoped**: only the acting seller's own organization(s), reusing the same
  `getEloquentQuery()` tenancy wall + `organizationIdsForUser()` pattern the other seller
  resources use. A seller must never see or touch another org's members.
- Gate on the org member-manage capability (Owner; Manager if the capability matrix allows).
- The org roles a seller can grant are the Organization module's (Manager, Seller
  Employee) — never staff roles.

## Tests
- Each admin resource shows ONLY its actor type (a seller never appears under Personel,
  etc.).
- Staff create obeys the escalation guard: an Admin cannot create or grant Super Admin; a
  Super Admin can.
- Seller/Customer oversight resources expose NO role-assignment and NO team management.
- Seller Team: a seller manages only their own org's members; a second seller is denied
  the first's org (membership isolation); org roles only, never staff roles.
- Keep the whole suite green.

## Live verify (browser, or drive Livewire if no browser)
Admin: create a staff user and grant Category Manager; open a seller under Satıcılar and
confirm oversight-only (no role controls); confirm Personel lists no sellers/customers.
Seller: invite a team member, assign Seller Employee, and confirm a second seller can't
see it.

## Finish
One commit per area, push `origin main` (human pushes — no creds on the box). `git rm
BUILD_USER_MANAGEMENT.md`, commit. Report the test line + a short live-verify note.
