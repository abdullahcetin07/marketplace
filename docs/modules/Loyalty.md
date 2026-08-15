# Loyalty (Puan) — Customer Points

**Status: PHASE 2 BUILT (2026-08-15).** Earning, the ledger, the admin screen, the
customer read API **and redemption at checkout** are live.

## Phase 2 — what shipped, and what to know

- **`LoyaltyContract` is the platform's fourth Core COMMAND port**, after Inventory's
  reservation and Order's cancellation and return. `hold → commit → release` is
  Inventory's shape on purpose: a shopper occupies something at the start of a payment
  that may never finish, and it must come back on its own if it does not.
- **A hold writes NO ledger row.** `loyalty_holds` is its own table, deleted rather
  than soft-deleted. The ledger records what happened; a hold is a claim on what
  might, and writing holds into it would make the balance a sum of intentions.
- **The spendable balance is the ledger sum minus what is held**, neither stored. A
  hold excludes its OWN group from that subtraction — and so does a **quote**, which
  was a real bug for one build: the pay step holds and then prices, so quoting without
  the exclusion measured the discount against a balance those points had already left
  and every redemption came back zero.
- **`amount_minor` becomes the reduced charge** rather than gaining a sibling. It is
  what PayTR is asked for and what the callback verifies against; a discount beside it
  would make every verified callback fail as the wrong amount. The discount lives on
  the payment only — no seller-order, commission, KDV or payout moves, because the
  platform funds it.
- **No cap** (owner's decision, 2026-08-15): points may cover the whole cart. That path
  skips PayTR — it rejects zero-amount orders — marks the payment paid with
  `provider_reference = 'points'`, commits the stock and the points, and emits
  `PaymentSucceeded` with a zero amount. `amount_minor = 0` is exactly what a later
  refund needs: no card money to return, only points.
- **A refund gives the points back, driven by Payment.** The fraction needs the card
  charge AND the discount and only Payment holds both, so the port is called from both
  refund actions rather than from a listener in Loyalty. The denominator is **card +
  points**: a 100 TL basket settled 60/40, refunded in full, must return all the
  points. A full refund is 1.0 outright rather than a division.
- **The purchase sweep earns on CASH** (ADR-082 §2.3): the order total minus its share
  of the basket's discount, spread proportionally across the seller-orders. It was
  reading the grand total, which would hand back points for the discount the points
  themselves bought. The share is floored, which rounds in the customer's favour.
- **`GrantPointsAction`'s guard is `=== 0`, not `<= 0`.** It was right for the three
  earns that existed and wrong the moment spending arrived: a redemption is negative
  because a spend is recorded, never deleted.

### Phase 2 deviations

1. **The points-only path repeats `SettlePaymentCallbackAction::settle()`** rather than
   restructuring it. That method is the verified-callback path for every real charge on
   the platform; reshaping it for a case with no gateway result, no hash and no amount
   to verify would put this feature's edge inside the code that settles everybody's
   money. The duplication is named in the docblock and a test asserts both paths reach
   the same end state.
2. **Two Core reads were added that the work order did not list:**
   `PaymentQueryContract::redemptionDiscountFor` (Order needs the discount to compute
   the cash earn base, and may not import Payment) and
   `OrderQueryContract::activeCartTotalFor` (the quote endpoint prices the live cart,
   and Loyalty may not import Order).
3. **`LoyaltyContract::quote()` takes an optional checkout group** — not in the work
   order's signature, and required by the bug described above.

**Phase 1 status (unchanged):** ADR-081–084. Earning, the append-only
ledger, the admin rates screen and the customer read API are live; the storefront
wiring ("Puanlarım") is a separate desktop task. **Phase 2 — redemption at
checkout (ADR-084) — is NOT built**: there is deliberately no `LoyaltyContract`
and no checkout change, because a port with nothing behind it invites checkout to
depend on a promise nothing keeps.

## What shipped, and what to know before touching it

- **The ledger is `loyalty_ledger`, append-only, and the balance is `SUM(points)`.**
  No `balance` column anywhere and a test asserts its absence: a stored total is a
  second source of truth that drifts silently, and the customer is the one who
  discovers it.
- **`(source_type, source_uuid)` is UNIQUE, and that is the whole idempotency
  story.** Signup keys on the customer, a review on the review, a purchase on the
  seller-order. A replayed event, a queue retry, a sweep run twice — none can
  credit twice, and the database decides rather than a check somebody remembered.
  `GrantPointsAction` both checks and catches the violation, because a check alone
  is a race between two workers.
- **Three earns, two shapes.** Signup and review are class-string listeners on
  `UserCreated` and `ReviewPublished`; purchase is a **sweep**, because the moment
  that matters is a DATE PASSING (`delivered_at + return_days`) and nothing emits
  an event for that.
- **`ReviewPublished` does not carry the author**, so `ReviewQueryContract` gained
  `authorCustomerUuidFor()`. Widening the event was the alternative and the worse
  one: a payload is a promise to every listener.
- **Order gained `pointsEligibleSellerOrders(asOf)`**, and it reads the delivery
  DATE through a new `ShipmentQueryContract::deliveredBefore()` — delivery is
  Shipping's fact (ADR-064). The reader knows nothing about points: already-credited
  orders come back every night and the ledger's unique key absorbs them, because a
  reader that filtered on Loyalty's table would be Order reaching into Loyalty.
- **The scheduler is part of the feature.** `loyalty:award-purchase-points` runs
  daily at 03:30 via `raftabul-scheduler` (systemd `schedule:work`, not cron —
  verified running). Unscheduled it is money-shaped and silent, which is the
  ADR-072 lesson this platform has already paid for once.
- **`Customer::factory()->create()` earns nothing**, and that is correct:
  `UserCreated` is dispatched by the registration action, not the model.
- **`settings()->set()` updates, it does not create.** `seedAll()` does not seed
  settings, so a test that toggles `loyalty.enabled` must seed `SettingsSeeder`
  first or it silently asserts against the code's fallback default.

### Recorded deviations from this spec

1. **`SettingType::Decimal` and `SettingGroup::Loyalty` are new enum cases.** The
   spec assumed the settings module could already hold a decimal rate; it could
   not — `loyalty.redeem.value` is 0,05 TL per point, and storing it as an integer
   or a float were both wrong (ADR-005: DECIMAL for rates). `cast()` returns the
   digits as a STRING rather than a float, so what a point is worth never depends
   on binary rounding.
2. **The permission is `loyalty.settings.manage`, an ability rather than a
   resource.** The generated CRUD set would produce `loyalty.delete` and
   `loyalty.restore` — verbs an append-only ledger has no operation for.


Loyalty is the platform's first **customer-facing rewards** context. A customer
earns points for three things — **signing up, having a review published, and
completing a purchase** — and (Phase 2) spends them as a **discount at checkout**.
How many points each event grants, and what a point is worth in TL, are
**operator settings the admin sets without a release** (ADR-083).

---

## 1. What Loyalty is — and is not

**It is** an append-only **points ledger** keyed by customer, one row per event
(`+100 signup`, `+50 review`, `+149 order`, `−50 redeemed`), with the **balance
computed on read** and never stored (ADR-081). It listens to other modules'
events **by class-string** and reads them through **Core contracts** only — it
imports **no module** (the strictest boundary on the platform, same as Payment,
Offer, Inventory, Order).

**It is NOT** money. A point is an **integer count**, not a minor-unit currency
amount — the minor-units rule (ADR-005) does not apply to the balance. The only
place a point meets money is the **redemption value** (a DECIMAL rate, TL-per-
point, like an exchange or tax rate), applied once at checkout.

**It is NOT** a seller cost. The redemption discount is **funded by the platform**
as a marketing expense — the seller receives their full sale amount and Loyalty
never touches commission or payout (ADR-084).

**It is NOT** a promotions/coupon engine, a tiered VIP program, or a referral
system. One flat earn rate per event, one flat point value. Those are future
modules if ever.

---

## 2. The rules that shape everything here

1. **The ledger is the source of truth; the balance is computed** (ADR-081).
   Append-only. No `balance` column to drift. A reversed credit is a new negative
   row, never an edit or delete.
2. **Purchase points are final only after the return window** (ADR-082). They are
   written when a delivered seller-order passes its return window un-returned — so
   a returned or cancelled order never grants points and **nothing is ever clawed
   back**.
3. **Only really-paid TL earns points** (ADR-082). The part of an order paid with
   points grants no new points — otherwise points would feed themselves.
4. **Earn rates and point value are settings, not code** (ADR-083). `settings()`
   keys, one admin page, every change audited.
5. **Redemption is a platform-funded discount through a Core command port**
   (ADR-084). Loyalty holds → commits → releases points; the seller payout is
   unchanged; a refund returns the spent points.
6. **Loyalty imports no module.** Core contracts + class-string events only.
   `LayeringTest` fails the build on any import.

---

## 3. Earning — three sources (ADR-082)

All three convert to an **integer** number of points (floor; no fractional
points). Each is written to the ledger by a **listener** subscribed to another
module's event **by class-string**, so Loyalty names none of those modules.

### 3.1 Signup — once per customer
On `Identity\...\CustomerRegistered` (class-string), grant
`settings('loyalty.earn.signup')` points, **once**. Idempotent per customer uuid
— a listener that runs twice, or a re-emitted event, must not double-credit
(guarded by a unique `(customer_uuid, source_type='signup')` in the ledger, or an
equivalent existence check).

### 3.2 Review — on publish, not on submit
On `Reviews\...\ReviewPublished` (class-string — the moderation-approved event,
NOT review-submitted), grant `settings('loyalty.earn.review')` points, **once per
review** (`source_type='review'`, `source_uuid=review_uuid`). Reviews are already
capped at one per delivered order line (ADR-067), so this cannot be farmed. A
review later deleted by moderation does not claw the points back in v1 (recorded
trade-off).

### 3.3 Purchase — after delivery + return window (the important one)
Points for a purchase are written when the seller-order is **finalized**:
`delivered_at + return_days` has passed and the order was **not** returned,
cancelled, or refunded. This mirrors Payment's auto-payout timing exactly.

- **Mechanism:** a **daily sweep** (a scheduled command in Loyalty) reads, through
  a Core query, every delivered-and-past-return-window seller-order not yet
  points-credited and not refunded, and writes one credit each. Loyalty subscribes
  to no Payment internals; it asks the Order/Shipping read side through a Core
  contract (`OrderQueryContract` gains a `pointsEligibleOrders(asOf)` reader, or
  equivalent — the one Core addition Phase 1 needs).
- **Amount:** `floor(paid_tl × settings('loyalty.earn.purchase_rate'))`, where the
  base is the seller-order's **KDV-included amount actually paid in TL** (same base
  as commission), **excluding any part paid with points** (rule §2.3). One credit
  per seller-order (`source_type='order'`, `source_uuid=order_uuid`), so a
  multi-seller basket earns several credits, each when that seller's parcel
  finalizes.
- **Idempotent:** the `(source_type, source_uuid)` guard means re-running the
  sweep never double-credits.

**The scheduler is part of the feature** — like Order expiry (ADR-072) and
Payment auto-payout, purchase points are inert without `schedule:run` on cron.

---

## 4. Admin configuration — "Puan Ayarları" (ADR-083)

The values live in the platform `settings()` table (operator-editable, no release
— the enum/lookup test in CLAUDE.md puts them here, not in code) and are edited
from **one Filament admin page** gated to Admin/Finance. Every write is audited.

| Setting key | Meaning | Default |
|---|---|---|
| `loyalty.enabled` | Master on/off for the whole system | `true` |
| `loyalty.earn.signup` | Points granted once on registration | `100` |
| `loyalty.earn.review` | Points per published review | `50` |
| `loyalty.earn.purchase_rate` | Points per 1 TL of finalized, really-paid spend | `1` |
| `loyalty.redeem.value` | TL a single point is worth at checkout (DECIMAL) | `0.05` |

The defaults give **5% back**: 100 TL spent → 100 points → worth 5 TL on the next
order. The operator can lower the rate or the value to reach any effective
percentage without a deploy.

Rules:
- **Points are integers.** 149.90 TL × 1 = **149** points (floor).
- **`redeem.value` is a DECIMAL rate** — the only money-adjacent number here — like
  a tax or exchange rate (ADR-005), never an integer minor-unit.
- **`loyalty.enabled = false`** stops all earning and hides redemption; existing
  balances are untouched.
- **Changing a rate is not retroactive** — it affects events from that point on;
  already-written ledger rows never change.

---

## 5. Redemption — Phase 2 (ADR-084)

A signed-in customer applies points at checkout for a **discount**, with **no cap
in v1** (they may cover the whole basket if the balance allows).

### 5.1 The Core command port
Order/Payment must not import Loyalty and Loyalty must not import them, so the
seam is a **Core command contract** `LoyaltyContract` — the platform's next
command port after Inventory's reservation and Order's cancellation/return ports:

- `hold(customer, points, checkoutGroup)` — at checkout, tentatively earmark the
  points (validated against the live computed balance). Returns the TL discount
  (`points × redeem.value`).
- `commit(checkoutGroup)` — on payment success, write the `−points` redemption row
  to the ledger. Idempotent on the checkout group.
- `release(checkoutGroup)` — on payment failure, expiry, or abandonment, drop the
  hold with no ledger effect.

A **hold** is transient (like an Inventory reservation); only `commit` touches the
append-only ledger.

### 5.2 The discount is platform-funded
The redeemed TL reduces **what the customer pays PayTR**, not what the seller
receives. Each seller-order settles on its **full** amount; the platform absorbs
the discount. Loyalty writes **no** commission, KDV, or payout figure — it only
lowers the charge and records the point debit. (Consequence: purchase points for
that same order are computed on the **really-paid TL only**, §2.3, so points spent
don't regenerate.)

### 5.3 Refund returns the points
If a points-redeemed order is refunded/returned/cancelled, the spent points are
**re-credited** to the customer (a new `+points` reversal row keyed to the
refund), and the TL actually charged is refunded through PayTR as usual. The
customer ends whole — neither out their points nor their money. Purchase points
for a refunded order were never written (§2.2), so there is nothing to reverse
there.

---

## 6. Storefront (Next.js, desktop session — a separate build step)

Phase 1:
- **Header / account hub:** "Puanlarım" — computed balance + its TL value
  (e.g. "520 puan · ≈ 26 TL").
- **`/hesap/puanlarim`:** the ledger as history — each row dated, with its source
  ("Üyelik +100", "Yorum +50", "Sipariş #123 +149", "Ödemede kullanıldı −50").
- Light "X puan kazandın" confirmations after signup / a published review.

Phase 2:
- **`/odeme` (checkout):** a "Puanını kullan" control — shows the balance, applies
  the discount, the remainder goes to the card.

All read through a customer-authenticated API surface; the balance and history are
**computed on read** (ADR-081), so a reversed or new row simply changes the next
response with nothing to invalidate.

---

## 7. Boundaries and tests

- **`LayeringTest`** — Loyalty imports no module. Events reach it by class-string;
  Order/Review/Identity reach it not at all. Redemption crosses through
  `LoyaltyContract` in Core.
- **Ledger is append-only** — the model refuses updates and deletes (like Audit,
  Activity, and the seller ledger). A reversal is a new row.
- **Balance-on-read** — a test asserts the balance equals the signed sum of the
  ledger and that no `balance` column exists to drift.
- **Idempotency** — signup credits once; the purchase sweep re-run credits once;
  `commit` on a checkout group credits once.
- **Earn base excludes redeemed TL** — a test buys with points and asserts the
  purchase credit is computed on the paid TL only.
- **Refund reversal** — a points-redeemed order refunded re-credits exactly the
  spent points.

---

## 8. Deliberately not in v1 (YAGNI)

- **Tiers / VIP levels**, birthday bonuses, referral points, campaign multipliers.
- **Expiry** — points do not expire in v1 (an `loyalty.expiry_days` setting is a
  clean future addition; the ledger already dates every row).
- **Redemption cap** — none (the operator can add a percentage or TL ceiling later
  without reshaping the ledger).
- **Seller-funded or shared-cost discounts** — platform-funded only (ADR-084).
- **Clawback of review points** on a later-deleted review.
- **Points on partial actions** — no points for adding to cart, wishlisting, etc.

---

## 9. ADRs

- **ADR-081** — Loyalty is a standalone module with a compute-on-read, append-only
  points ledger; imports no module; listens by class-string.
- **ADR-082** — Points are earned from three events; purchase points are finalized
  only after the return window (no clawback) and only really-paid TL earns.
- **ADR-083** — Earn rates and point value are operator `settings()`, not code;
  the point is an integer count, the value a DECIMAL rate; default 5% back.
- **ADR-084** — Redemption is a platform-funded checkout discount applied through a
  Core `LoyaltyContract` command port (hold → commit → release); a refund
  re-credits the spent points. (Phase 2.)
