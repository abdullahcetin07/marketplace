# BUILD — Pre-launch security fixes (backend)

**Status:** Ready. Found by a pre-launch security audit (2026-08-18). Four backend
findings; **three gate launch** (money integrity / money redirection), one is low.
The storefront XSS finding is already fixed (commit `60887c2`). Everything else the
audit checked was clean (callback hash auth, amount tampering, IDOR surface, guard
isolation, mass assignment, rate limiting, secrets, injection, CORS/session config).

Everything runs in Docker; `make check` must pass. Each fix its own commit + test.

---

## 1. HIGH-ish (fix before real money) — Points mis-refunded across multiple partial refunds of one order

**Files:** `Loyalty/Infrastructure/Commands/LoyaltyRedemption.php:151-187` (`reverse()`);
`Payment/Application/Actions/RefundLinesAction.php:432-436,489-498` (`refundedFraction()`);
`Payment/Application/Actions/RefundPaymentAction.php:154-158,429-438`;
`database/Modules/Loyalty/migrations/..._create_loyalty_ledger_table.php:60` (unique key).

**Defect.** A points-funded order refunded in TWO partial returns mis-credits points.
`reverse()` keys its ledger row on `sourceUuid = "{checkout_group}:{cause}"`. Two partial
**returns** share `cause=return` → identical key → the `(source_type, source_uuid)` unique
index silently drops the second reversal. Trace (2-unit, `spent` points):
- Return #1 (1 unit): `fraction ≈ 0.5` → credits `floor(spent*0.5)`, key `group:return`.
- Return #2 (2nd unit): `fully=true` → `refundedFraction()` returns **1.0** → wants
  `floor(spent*1.0)` but key exists → **0 credited**. Customer shorted ~half their points
  (card + seller-ledger legs are correct; only the points leg is wrong).

Naive fixes are also wrong: just making the key unique per refund makes the `fully→1.0`
branch over-credit `floor(spent*1.0)` ON TOP of the earlier `floor(spent*0.5)` → 1.5×
over-credit (platform loss).

**Fix — incremental + idempotent per refund event:** key the reversal on the individual
`PaymentRefund` uuid, and credit the **delta**:
`pointsToCredit = floor(spent * cumulativeRefundedFraction) − pointsAlreadyReversed(group)`,
where `pointsAlreadyReversed(group) = SUM(points)` of prior `Reversal` rows for that group.
N partial refunds then sum to exactly `spent`, regardless of order/granularity. **Test:**
a points-funded 2-unit order refunded 1 unit then 1 unit re-credits exactly `spent` points
total (not ~1.5×, not ~0.5×); a single full refund still returns `spent`.

## 2. MEDIUM — Points double-spend race: `hold()` reads balance without locking

**File:** `Loyalty/Infrastructure/Commands/LoyaltyRedemption.php:79-112` (`hold()`), `192-205` (`spendable()`).

`hold()` opens a transaction but computes `spendable()` with a plain `SUM` — **no
`lockForUpdate`**. Two concurrent checkouts for the **same customer, different checkout
groups** each exclude only their own group's hold, so both read `balance=B, held=0` and
each hold up to `B`; `loyalty_holds` is unique only per checkout group, so both coexist
(total held `2B` vs balance `B`). Each settle commits its own `−B` row → ledger `−B`, and
the platform funded more discount than the customer had points.

**Fix:** take `lockForUpdate()` on the customer's `loyalty_ledger` rows before reading
`spendable()` — the exact pattern `CreatePayoutAction.php:88-91` already uses on the seller
ledger. **Test:** two concurrent holds for one customer across two groups can't jointly
exceed the balance.

## 3. MEDIUM — Manager can seize payout-IBAN control (separation-of-duties bypass → money redirection)

**Files:** `Organization/Domain/Enums/OrganizationRole.php:66-90` (capability matrix);
`Organization/Presentation/Policies/OrganizationMemberPolicy.php:48-55` (`updateRole`);
`Organization/Application/Actions/ChangeMemberRoleAction.php:39-43`;
`Organization/Presentation/Requests/InviteMemberRequest.php:32-44`;
`Organization/Presentation/Controllers/Api/BankAccountController.php:41-46`.

**Chain:** `Manager` has `MemberUpdateRole` but NOT `BankAccountUpdate`; `Finance` HAS
`BankAccountUpdate`. `updateRole` only refuses when the *target* is Owner — it doesn't
forbid self-targeting and doesn't restrict which role a `MemberUpdateRole` holder may
grant. So a Manager `PATCH`es its own membership to `role=finance`, then `PUT
…/bank-account` to replace the payout IBAN with their own. `BankAccountController::update`
does the upsert with no re-verification/approval. Next admin payout of that seller's
balance wires to the attacker. (Or invite a throwaway email as `Finance`.) Within-tenant,
needs an existing Manager → Medium, but it defeats a deliberate control over real-money
destination.

**Fix (do 1 + 3):**
1. In `updateRole`/invite, forbid granting a role whose capability set is **not a subset**
   of the actor's own (a `MemberUpdateRole` holder can't confer `BankAccountUpdate` it
   lacks); and forbid self-role-change in `OrganizationMemberPolicy::updateRole`.
3. On payout-IBAN change, require re-verification / owner-approval (or at minimum an
   audit-alert + cool-down) — standard anti-fraud on payout destinations.

**Test:** a Manager cannot assign Finance (to self or others) nor otherwise gain
`BankAccountUpdate`; changing the IBAN emits the audit signal / requires the approval step.

## 4. LOW — `pay` runs side effects before the ownership check

**File:** `Payment/Presentation/Controllers/Api/PaymentController.php:64-78`.

`store()` calls `initiate->run($group, …)` and only afterwards checks
`payment.customer_id !== actor`. By then the action has resolved the victim's customer,
held their points, upserted the payment, and — on the **points-only path** — committed
stock, spent the victim's points and marked their order Paid, none of which the post-hoc
throw rolls back. Bounded by the unguessable `checkout_group_uuid`, so LOW.

**Fix:** resolve the group's customer and compare to `current_actor()` **before** calling
`initiate->run()` (or move the actor check into the action, before any effect). **Test:**
a customer calling `pay` on another customer's checkout group is refused with no state
change.

---

## After it lands

`make check` green; migrations if the reversal keying changes (check the ledger unique
index). Reply here when done — the desktop session will re-verify the points partial-refund
flow live on the sandbox (the earlier order flow is reusable). Then the OWNER `.env`
checklist (in HANDOFF) must be confirmed on the production server before go-live.
