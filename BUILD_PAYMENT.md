# Work order — Payment module (backend), phased P1–P5

**Status:** owner-approved 2026-08-04. Spec: [docs/modules/Payment.md](docs/modules/Payment.md).
Decisions: **ADR-060** (settlement + PayTR + commit-on-success), **ADR-061** (commission
engine), **ADR-062** (seller ledger + payout). Disposable — `git rm` when the module lands.

**Session split:** BACKEND → the **server session**. The desktop session does the
storefront `/odeme` rework (embed PayTR iframe, result pages) AFTER P1's API exists — do
**not** touch `storefront/`. Build **phase by phase**, `make check` green + push + report
after each phase before starting the next.

**Sprint rule (ADR-018):** if anything here conflicts with the spec or an ADR, STOP and
report — do not pick a side. The spec outranks this file.

---

## Non-negotiables for THIS module (the ones that bite hardest)

- **Money = integer kuruş everywhere.** `DECIMAL` only for the commission **rate** (ADR-005).
  Commission, seller net, payout, refund reversal — all integer minor units. A float here is
  a financial bug. APIs format money as decimal strings.
- **Payment imports NO module.** Core contracts + class-string event subscriptions + the
  gateway port only. `LayeringTest` must stay green both directions.
- **No card data, ever.** PayTR iFrame holds the card + 3DS. Store a PSP reference and the
  result — never a PAN, never a CVV.
- **The callback is hash-verified and IDEMPOTENT.** Same `merchant_oid` may arrive many
  times; process once, always answer plain `"OK"`. A bad/replayed hash changes nothing.
- **Append-only:** Payment audit, `seller_ledger_entries`, payout records — models refuse
  update/delete.
- `current_actor()` / named guards, never `auth()->user()`. Repeat-safe PSP calls + mail in
  `BaseAction::after()` (after commit). Strict types every file.

---

## Scaffold (before P1)

`app/Modules/Payment/{Domain,Application,Infrastructure,Presentation}` + service provider +
`config/` entry + module README + `LayeringTest` registration. `PermissionRegistry` resource
for payment/payout admin abilities. Register in `docs/modules/README.md` index.

---

## P1 — Collection core ("ödeme çalışıyor")

**The goal:** a buyer pays for a checkout group through PayTR and, on the verified success
callback, the group's orders are confirmed and their stock is committed. **This closes
ADR-054/057.**

1. **Core `PaymentGatewayContract`** (in `app/Core`, provider-agnostic):
   `initiate(PaymentIntentDTO): GatewaySessionDTO` · `verifyCallback(array $raw): GatewayResultDTO`
   · `refund(PaymentRefundDTO): GatewayRefundResultDTO`. DTOs in `Payment/Domain/DTOs` (`...DTO`).
2. **`PayTrGateway`** (Infrastructure) — the only implementation:
   - `initiate`: POST PayTR `get-token` — `merchant_oid = payment.uuid`, `payment_amount` in
     **kuruş**, `user_basket`, `email`, `user_ip`, `currency=TL`, 3DS on, `test_mode` from
     config, `no_installment`/`max_installment` per config (installments pass-through, §8),
     `merchant_ok_url`/`merchant_fail_url` → storefront result pages, **hash** = HMAC over the
     documented fields with `merchant_key` + `merchant_salt`. Returns the iframe token.
   - `verifyCallback`: recompute the hash over PayTR's posted fields; **reject on mismatch**;
     map `status` → success/failed.
   - `refund`: PayTR iade API (used in P5).
   - Credentials from `config('payment.paytr.*')` ← `.env` (owner enters
     `PAYTR_MERCHANT_ID/KEY/SALT`, `PAYTR_TEST_MODE=1` to start). **Never commit secrets.**
3. **`Payment` aggregate** — one per Order `checkout_group`. Columns: uuid, `checkout_group_uuid`,
   `amount_minor`, `currency`, `status` (`pending|paid|failed|expired|refunded|partially_refunded`),
   PSP reference fields, timestamps. Append-only audited. Public id = uuid.
4. **`InitiatePaymentAction`** — given a checkout group (read its orders + Σ `grand_total`
   through the **Core Order read contract**, no Order import), create the Payment (`pending`),
   call the gateway, return the iframe token. Guard: one live Payment per group (idempotent
   re-initiate returns the existing session).
5. **Callback endpoint** `POST /api/v1/payments/paytr/callback` (public, no auth, throttled):
   `verifyCallback` → on **success**, in a transaction and **idempotently**:
   - Payment → `paid`;
   - for each order in the group: confirm (`awaiting_payment` → paid/confirmed) and **commit**
     its held reservation via Inventory's Core command port (the `order_uuid:variant_uuid`
     string key — ADR-049, never a uuid);
   - emit `PaymentSucceeded` (class-string consumers later);
   - **respond `"OK"` (plain text)** or PayTR retries forever.
   On **failed/expired**: Payment → `failed`, **release** the reservations, orders → a
   cancellable/failed state, respond `"OK"`.
6. **Status read** `GET /api/v1/payments/{uuid}` (owner-scoped) — for the storefront to confirm
   after the browser redirect lands on the result page.
7. **Storefront-facing initiate** `POST /api/v1/checkout/{group}/pay` (or `POST /api/v1/payments`)
   → `{ payment_uuid, iframe_token }`. (The desktop session builds the iframe against this.)

**Tests (pgsql where money/uuid is involved):** hash accept/reject; idempotent double-callback
processes once; success commits every order's reservation (assert Inventory movement) and flips
the orders; failure releases; amount is exact kuruş; a non-uuid/unknown group 404s not 500s
(the trap — 5th watch). `make check` green.

**Report after P1:** the initiate + callback signatures, the result-page URLs PayTR redirects
to, and confirmation that stock commits on success — so the desktop session can wire `/odeme`.

---

## P2 — Commission engine (ADR-061)

- `commission_rules` table: `seller_org_uuid?`, `product_uuid?`, `brand_uuid?`, `category_uuid?`
  (all nullable = wildcard), `rate DECIMAL`, `priority INT`, `is_active`. The all-null row = the
  platform default. Admin-managed (Filament resource + seed a sensible default).
- **Resolver** (`CommissionResolver`, Domain service): for an order line (product/brand/category/
  seller from the line's snapshot), pick the active rule whose non-null scopes all match, ranked
  by **specificity** (count of set scopes), tie-broken by `priority` then recency; fall back to
  default. Category match is **subtree** (a rule on a parent covers its descendants).
- **Base = KDV-INCLUSIVE line total**, integer kuruş; `commission_minor = round(rate × base)`
  (define rounding once, half-up, in one helper).
- **Snapshot at payment:** when P3 credits the ledger, freeze the resolved `rate` and
  `commission_minor` onto the order line (new columns on `order_lines`, Order-owned — coordinate
  the column add exactly as ADR-055 added `tax_rate_id`, exempt from `CatalogBoundaryTest` by
  name if needed). A later rule change never moves a settled commission.
- Tests: specificity ordering (seller+category beats brand beats category beats default);
  subtree match; KDV-inclusive base; rounding; snapshot immutability.

---

## P3 — Seller balance ledger (ADR-062)

- `seller_ledger_entries` (append-only; model refuses update/delete): `seller_org_uuid`, `type`
  (`sale_credit|commission_debit|payout_debit|refund_debit|refund_commission_credit`),
  `amount_minor` (signed), `order_uuid?`/`payment_uuid?`/`payout_uuid?`, `created_at`.
- On `PaymentSucceeded`, per seller in the group: append `sale_credit` (order KDV-inclusive
  total) + `commission_debit` (P2 commission). Balance = Σ `amount_minor`, computed on read
  (indexed by seller). Never a stored balance column.
- Tests: balance = net of commission; append-only refusal; multi-seller group splits correctly.

---

## P4 — Payout (admin, manual/recorded)

- `Payout` aggregate: uuid, `seller_org_uuid`, `amount_minor`, `status` (`pending|paid|failed`),
  external transfer reference, admin actor, timestamps. Creating one appends a `payout_debit`.
- Guard: amount ≤ available balance; concurrent payouts serialized so balance can't go negative.
- Admin Filament resource + API to create/mark-paid a payout. **The software moves no money** —
  it records the reference of a transfer a human/bank made.
- Tests: cannot exceed balance; concurrency guard; balance drops by the debit.

---

## P5 — Refund

- `RefundPaymentAction` (admin, or a policy-allowed customer cancel): call `gateway.refund`
  (PayTR iade); on success append `refund_debit` + `refund_commission_credit` to the seller
  ledger, **restock** via Inventory (mirror of P1's commit), move Payment/orders to
  `refunded`/`partially_refunded`.
- Refund-after-payout: allowed to drive balance negative; the next payout is blocked until the
  balance is non-negative (the ledger makes this safe by construction).
- Tests: ledger reverses; restock happens; partial refund; refund-after-payout blocks payout.

---

## Credentials (owner, on the server — never in git, never sent to the desktop session)

`.env`: `PAYTR_MERCHANT_ID`, `PAYTR_MERCHANT_KEY`, `PAYTR_MERCHANT_SALT`, `PAYTR_TEST_MODE=1`.
Start in **test mode** with PayTR's sandbox/test cards. `php artisan config:clear` after.

## Frontend follow-up (desktop session — NOT you)

After P1: the storefront `/odeme` changes from "place → ödeme bekliyor" to "place → initiate
payment → embed PayTR iframe → PayTR redirects to a result page → confirm via
`GET /payments/{uuid}`". The desktop session builds that against P1's API. Nothing for you in
`storefront/`.
