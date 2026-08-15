# BUILD — Loyalty (Puan) · Phase 2: redemption at checkout

**Status:** Ready. Decision: **ADR-084** (`docs/Architecture_Decision_Record.md`),
amendment log #19. Spec: **`docs/modules/Loyalty.md` §5**. Depends on **Phase 1**
(`BUILD_LOYALTY_P1.md`) being live — the ledger, balance, settings and read API must
exist first.

This adds **spending** points as a **platform-funded discount at checkout**. Phase 1
(earning) is unchanged. Everything runs in Docker; `make check` must pass. Loyalty
still **imports no module** — the seam is a Core command port.

---

## The shape (ADR-084)

One Payment per checkout group (ADR-060). A customer applies points → the PayTR
charge for the whole group drops by the discount → the **platform absorbs it**, each
seller-order still settles on its **full** amount. Points move through a Core command
port `hold → commit → release`; a refund re-credits.

```
/odeme quote (preview)      → discount + payable, no state change
initiate payment (+points)  → HOLD points, charge PayTR (total − discount)
PayTR callback success      → COMMIT (ledger −points) + existing inventory commit
PayTR callback fail/expiry  → RELEASE (no ledger effect)
refund / return / cancel    → RE-CREDIT the spent points (+points reversal row)
```

## P2.1 — The Core command port `LoyaltyContract`

Add to `app/Core` (the platform's next command port after Inventory reservation and
Order cancellation/return). Payment/Order call it; **neither imports Loyalty**, and
Loyalty imports neither.

- `quote(customerUuid, cartTotalMinor, requestedPoints|max): LoyaltyQuoteDTO` — pure,
  no state: how many points would actually be spent and the resulting discount +
  payable, clamped to the live balance and the cart total. (No cap — points may cover
  the whole cart, ADR-084/§8.)
- `hold(customerUuid, points, checkoutGroupUuid): LoyaltyHoldDTO` — earmark points for
  the group, validated against the **live computed balance**. Transient (like an
  Inventory reservation); no ledger row yet. Idempotent per checkout group — a retry
  re-holds the same, never stacks.
- `commit(checkoutGroupUuid)` — write the single `−points` row (`source_type=Redemption`,
  `source_uuid=checkoutGroupUuid`). Idempotent on the group.
- `release(checkoutGroupUuid)` — drop the hold, no ledger effect.
- `reverse(checkoutGroupUuid, cause)` — the refund path: a `+points` row
  (`source_type=Reversal`, keyed to the refund) returning exactly what was committed.

`LoyaltyPointSource` gains `Redemption` and `Reversal` (deferred in P1). The hold store
is a small table or cache keyed by checkout group (a hold is transient state, not the
append-only ledger — do not write holds to `loyalty_ledger`).

## P2.2 — Quote endpoint (preview for the storefront)

`POST /api/v1/loyalty/redeem/quote` (customer-auth), body `{ points }` or
`{ use_max: true }`. Reads the caller's **active cart** total + balance + settings and
returns, all money as decimal strings (ADR-005):
```json
{ "points_applied": 100, "discount": "5.00", "cart_total": "100.00",
  "payable": "95.00", "currency": "TRY", "max_points": 520 }
```
Pure preview — **no hold, no state**. `loyalty.enabled=false` → `max_points: 0`. This
is what the checkout page calls as the customer toggles "Puanını kullan".

## P2.3 — Apply at payment (the hold + reduced charge)

The pay step carries the chosen points: `POST /api/v1/checkout/{group}/pay` accepts an
optional `{ points }`. Payment then:
1. Calls `LoyaltyContract::hold(customer, points, group)` — clamped to live balance.
2. Charges PayTR **`total − discount`** (the `merchant_oid`/amount PayTR sees is the
   reduced figure). The discount is the platform's cost — **no seller-order amount,
   commission, or KDV changes** (ADR-084/061).
3. Records the redemption on the Payment so the callback and the refund path can find
   it (e.g. `points_spent` + the discount minor-units on `payments`).

**Edge — points cover the whole cart (`payable == 0`):** there is no card charge.
Since there is no redemption cap (ADR-084), this is reachable and MUST be handled:
skip PayTR, mark the payment **paid via points** (a points-only settlement — still one
Payment per group), and run the **same success path** (commit the loyalty hold + the
existing Inventory commit + order placement→paid). Do not send a 0-amount order to
PayTR — it will reject it. Record this so a refund still knows there was no card money
to return (only points to re-credit).

## P2.4 — Commit / release on the callback

The **hash-verified PayTR callback is the truth** (ADR-060), not the redirect:
- **Success** → `LoyaltyContract::commit(group)` alongside the existing Inventory
  commit. One `−points` ledger row, idempotent (the callback is retried).
- **Failure / payment-window expiry (ADR-072)** → `LoyaltyContract::release(group)`,
  so the held points return to spendable. A released hold leaves **no** ledger row.

## P2.5 — Refund re-credits the points (closes ADR-084)

On `PaymentRefunded` (the event Payment already emits, carrying its `cause` —
ADR-065), Loyalty **re-credits** the points spent on that checkout group:
`LoyaltyContract::reverse(group, cause)` writes a `+points` row keyed to the refund.
The TL actually charged is refunded through PayTR as today. Net: the customer is
**whole** — points back, money back. A **partial** refund (line-level, ADR-064/S4)
re-credits **proportionally** to the refunded fraction of the group (document the
rounding: floor to integer points, and never re-credit more than was committed).

## P2.6 — Earn base already excludes redeemed TL

Confirm the P1 purchase sweep computes points on the **really-paid TL** (cart total −
discount), not the pre-discount total (ADR-082/§2.3) — points must not regenerate on
money paid with points. If P1 used the full amount, fix it here and add the test.

## P2.7 — Tests (Feature)

1. Quote clamps to balance and to cart total; `use_max` spends min(balance, cart).
2. Hold reduces the PayTR charge by the discount; the seller-order amounts,
   commission and KDV are **unchanged**.
3. Callback success commits exactly one `−points` row (idempotent on retry); failure
   and expiry release with **no** ledger row.
4. **`payable == 0`**: no PayTR call, payment marked paid-via-points, hold committed,
   inventory committed, order reaches paid.
5. Refund re-credits exactly the committed points; a partial refund re-credits the
   proportional floor and never more than committed.
6. A points-paid order earns purchase points on the **paid** TL only (P2.6).
7. Boundary: `LayeringTest` green — Payment/Order reach Loyalty only through
   `LoyaltyContract`; Loyalty imports nothing. Double-spend: two concurrent checkouts
   cannot commit more than the balance (the hold reserves).

## P2.8 — Docs

Update `docs/modules/Loyalty.md` status to "Phase 2 built", record any deviations
(especially the `payable == 0` handling and the partial-refund rounding), and note the
new `LoyaltyContract` in the Core contracts list. `app/Modules/README.md` unchanged.

---

## Boundary reminders (fail the build)

- **Loyalty imports no module; Payment/Order import no Loyalty.** The only crossing is
  `LoyaltyContract` in `app/Core`. `LayeringTest` enforces it.
- **The discount is platform-funded** — never write it into a seller-order amount,
  commission, KDV, or payout. It lives on the Payment only.
- **A hold is transient; only commit touches the append-only ledger.** Never write a
  hold row into `loyalty_ledger`.
- **Money is minor units server-side, decimal strings on the API.** A point is an
  integer count.

## After it lands

`make check` green; `php artisan migrate` (the holds table, the `payments`
redemption columns); `optimize:clear`. Verify end-to-end on PayTR sandbox: a cart with
a points balance → apply points → PayTR charge is reduced → callback commits the
`−points` row → a refund re-credits it. Test the `payable == 0` path with a balance
that covers a small cart. **The storefront `/odeme` "Puanını kullan" control is already
built against the quote + `pay {points}` contract and stays hidden until this ships.**
