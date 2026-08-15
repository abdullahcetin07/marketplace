# BUILD — Loyalty (Puan) · Phase 1: earn + balance + admin + read API

**Status:** Ready. Decision: **ADR-081/082/083** (`docs/Architecture_Decision_Record.md`),
amendment log #19. Spec: **`docs/modules/Loyalty.md`** (read §1–4, §7 first). This is
**Phase 1 only** — earning, the ledger, the admin config page, and the customer read
API. **Redemption at checkout is Phase 2 (ADR-084) and is OUT OF SCOPE here** — build no
`LoyaltyContract`, no checkout change, no discount.

Everything runs in Docker; `make check` must pass. New module — `LayeringTest` and the
enum/lookup and money rules all apply.

---

## What Phase 1 delivers

A customer earns points three ways and can see the balance + history. Nothing is spent
yet.

- **Signup** → `loyalty.earn.signup` points, once per customer.
- **Published review** → `loyalty.earn.review` points, once per review.
- **Finalized purchase** → `floor(paid_TL × loyalty.earn.purchase_rate)`, once per
  seller-order, after the return window.
- Admin sets the four rates + the point value on one page.
- Customer reads balance + ledger over an authenticated API (storefront wiring is a
  separate desktop task).

---

## P1.1 — Scaffold the module

`app/Modules/Loyalty/{Domain,Application,Infrastructure,Presentation}` + a
`LoyaltyServiceProvider` (register it), `config/loyalty.php` if needed, and the module
README (per CLAUDE.md, module docs live in `docs/modules/Loyalty.md` — the in-module
README is a stub pointer). `LayeringTest` must cover the module: **it imports no other
module** — Core contracts + class-string events only.

## P1.2 — Domain: the ledger

- **`LoyaltyLedgerEntry`** model — **append-only** (refuses `update`/`delete` like
  `AuditEntry`/`ActivityEntry`/the seller ledger). Fields: `id` BIGINT PK, `uuid` public
  id, `customer_uuid`, `points` **signed integer** (earn positive; Phase 2 adds
  negatives), `source_type` (enum), `source_uuid` (the event's subject uuid), optional
  `meta` JSON (e.g. the rate applied, for audit), `created_at`.
- **`LoyaltyPointSource`** enum (no `Enum` suffix, ADR-007) — `Signup`, `Review`,
  `Purchase`. (Phase 2 will add `Redemption`, `Reversal`; do not add them now — YAGNI.)
  Adding a case requires code to handle it → enum, not a table.
- **Balance is computed on read**: a `PointsBalance` reader returns the signed
  `SUM(points)` for a customer. **No `balance` column anywhere** — a test asserts it
  doesn't exist and that the balance equals the ledger sum (ADR-081).
- DTOs in `Loyalty/Domain/DTOs/` with the `DTO` suffix (ADR-021).

## P1.3 — Infrastructure: migration + repository

- `loyalty_ledger` migration. **Unique `(source_type, source_uuid)`** — this is the
  idempotency guard: signup keys on the customer uuid, review on the review uuid,
  purchase on the seller-order uuid, so no event ever credits twice. Index
  `customer_uuid`. Money rule note: **there is no money column here** — `points` is a
  count, not minor units (ADR-005 does not apply); do not add a currency.
- Repository with `$with` eager loads declared there (strict mode — ADR/CLAUDE note).
- Factory for tests.

## P1.4 — Earn: one action, class-string listeners

- **`GrantPointsAction`** (`handle()`, one transaction) — writes one ledger row for
  `(customer, points, source_type, source_uuid, meta)`, **idempotent**: if the unique
  key exists, it's a no-op success (a re-emitted event must not double-credit, nor throw).
- **Signup listener** — subscribe **by class-string** to Identity's customer-registration
  event. Grant `settings('loyalty.earn.signup')` with `source_type=Signup`,
  `source_uuid=customer_uuid`. (Do NOT import Identity — resolve the event class by
  string, read the customer uuid off the payload.)
- **Review listener** — subscribe **by class-string** to `ReviewPublished` (the
  moderation-approved event, NOT submitted). Grant `settings('loyalty.earn.review')`,
  `source_type=Review`, `source_uuid=review_uuid`, to the review's author customer uuid.
- Points are **floored integers**. If `loyalty.enabled` is false, listeners no-op.

## P1.5 — Earn: the purchase sweep

- **Core contract addition** — `OrderQueryContract` (in `app/Core`) gains a reader that
  returns finalized-but-not-yet-considered seller-orders as of a timestamp: **delivered,
  `delivered_at + return_days` elapsed, not returned/cancelled/refunded**, each with
  `order_uuid`, `customer_uuid`, the **KDV-included amount actually paid in TL** (minor
  units) and currency. Name it e.g. `pointsEligibleSellerOrders(CarbonInterface $asOf)`.
  Order implements it. (Loyalty filters out already-credited orders via its own ledger
  idempotency, so the query need not know about points.)
- **`AwardPurchasePointsAction`** / a scheduled command **`loyalty:award-purchase-points`**
  — daily. For each eligible seller-order: `points = floor(paid_TL ×
  settings('loyalty.earn.purchase_rate'))`, granted via `GrantPointsAction` with
  `source_type=Purchase`, `source_uuid=order_uuid`. **Register it in the scheduler** — like
  Order expiry (ADR-072) and auto-payout, this is inert without cron; say so in the PR.
- Phase-1 note: with no redemption yet, "really-paid TL" == the full seller-order amount.
  Read it from the paid amount, not a hard-coded total, so Phase 2 (points-funded
  discount) needs no change here.

## P1.6 — Admin config: "Puan Ayarları"

- Five `settings()` keys with defaults (**5% back**): `loyalty.enabled=true`,
  `loyalty.earn.signup=100`, `loyalty.earn.review=50`, `loyalty.earn.purchase_rate=1`,
  `loyalty.redeem.value=0.05`. Seed the defaults.
- **One Filament admin page** ("Puan Ayarları") to edit them, gated to **Admin/Finance**.
  Register the permission through the module provider (`PermissionRegistry`), attach in
  `RolePermissionSeeder`, `make permissions`. Every write audited (settings already audit).
- `loyalty.redeem.value` is a **DECIMAL** input (TL per point) — it's a rate, not an
  integer; the earn fields are integers. Validate ranges (non-negative).
- Reads use `settings()` at event time, so a rate change is **not retroactive** — already
  written rows never change.

## P1.7 — Read API (customer-authenticated)

- `GET /api/v1/loyalty/balance` → `{ points, value: "26.00", currency }` (value =
  `points × redeem.value`, formatted as a decimal string — ADR-005 API rule).
- `GET /api/v1/loyalty/ledger` → paginated history, newest first: each row
  `{ uuid, points, source_type, source_uuid, created_at }` (+ a human label the
  storefront can localize). Authenticated as the customer; a customer sees **only their
  own** ledger.
- API resources; money/value as decimal strings; public ids are uuids (ADR-005/§7).

## P1.8 — Tests (Feature unless noted)

1. Signup grants once; a second registration event for the same customer does not
   double-credit (idempotency key).
2. `ReviewPublished` grants once per review; a submitted-but-unpublished review grants
   nothing.
3. The sweep grants `floor(paid × rate)` per eligible seller-order; a returned/refunded
   order grants nothing; re-running the sweep does not double-credit.
4. Balance equals the signed ledger sum (Unit if no DB; otherwise Feature) and **no
   `balance` column exists**.
5. `loyalty.enabled=false` suppresses all earning.
6. Admin page writes settings and is denied to non-Admin/Finance actors.
7. Read API returns the caller's balance + ledger and **refuses another customer's**.
8. Boundary: `LayeringTest` green — Loyalty imports no module; the append-only model
   refuses update/delete.

## P1.9 — Docs + sweep

`docs/modules/Loyalty.md` exists (this spec) — update its status header from "SPEC" to
"Phase 1 built" with what shipped, any recorded deviations, and where to look. Module
README stub points at it. `app/Modules/README.md` index row. Strict-types + no-`dd()`
sweep.

---

## Boundary reminders (fail the build)

- **Imports no module.** Identity's registration event, `ReviewPublished`, and the Order
  read all cross by **class-string** / **Core contract** — never an import. `LayeringTest`
  enforces both directions.
- **Ledger append-only.** No update/delete escape hatch (CLAUDE.md non-negotiable #9).
- **A point is a count, not money.** No minor-units currency on the ledger; only
  `redeem.value` is money-adjacent and it's a DECIMAL rate.
- **Settings, not enums, for the rates** (they change without a release); **enum**, not a
  table, for `source_type` (adding a case needs code).

## After it lands

`make check` green; `php artisan migrate`; seed the settings defaults;
`make permissions`; **confirm the scheduler runs `loyalty:award-purchase-points`** (it is
money-shaped and silent if the scheduler isn't active — the recurring lesson from ADR-072).
Then the storefront gets its "Puanlarım" + `/hesap/puanlarim` wiring (separate desktop
task) and customers start seeing balances. **Phase 2 (redemption, ADR-084) is a separate
work order** — do not start it here.
