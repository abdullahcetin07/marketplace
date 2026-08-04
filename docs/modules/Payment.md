# Payment

**Status: SPEC — not built. Approved architecture (owner, 2026-08-04); ADR-060–062.**

Payment is the module that turns a placed-but-unpaid checkout into money collected,
splits that money into what the platform keeps and what each seller is owed, and
tracks paying the sellers out. It is the platform's first module to take real money,
and the newest and largest after Order.

It is the sprint Order deferred to: an Order stops at **awaiting payment** and holds
its stock reservation (ADR-057); Payment is what moves it forward. **ADR-054/057
promised the stock COMMIT would happen on payment success — Payment is where that
promise is kept.**

---

## The rule that outranks everything (repeated because it costs the most here)

> Money is an **integer of minor units** (kuruş). Never a float. `DECIMAL` is only for
> **rates** — a commission percentage, a tax rate (ADR-005). A commission of "15%"
> is a `DECIMAL` rate; the **lira it produces** is an integer of kuruş. APIs format
> money as decimal strings.

Payment does more money arithmetic than any module before it — commission, seller
net, payout, refund reversal — and every one of them is integer kuruş. A float in
this module is a financial bug, not a rounding curiosity.

---

## 1. What Payment is — and is not

**Payment IS:** collecting one payment for a whole basket through a PSP; confirming
the orders and committing their stock when that payment succeeds; computing and
freezing commission; crediting each seller's balance with what they are owed; and
recording the manual payouts that settle those balances. Refunds reverse all of it.

**Payment is NOT** the checkout (Order owns the cart→orders split and the
reservation), NOT the catalogue or the offer, NOT shipping, NOT invoicing/e-fatura
(commission produces a number; issuing the legal invoice is out of scope), and NOT a
licensed payment institution — see §2's cost note.

**Payment imports NO module** — the platform's strict boundary (as Offer, Inventory
and Order before it). It reads Catalog/Order/Organization/Store through **Core
contracts**, drives Inventory's reservation **commit** through the Core command port
Order already uses, subscribes to other modules' events **by class-string**, and
talks to the PSP only through a **provider-agnostic port** (`PaymentGatewayContract`)
with a single **PayTR** adapter. `LayeringTest` fails the build on any import, both
directions.

---

## 2. Settlement: single merchant, manual payout (ADR-060)

The platform is **one merchant** at the PSP. Every buyer payment lands in the
platform's own PayTR merchant account. Sellers are **not** submerchants; the platform
records internally what each seller is owed (§7) and pays them out **manually/in
batches** by its own means. The PSP splits nothing.

**Why (owner choice 2026-08-04):** the submerchant/marketplace product (iyzico
Pazaryeri, PayTR's marketplace) is the lower-liability model but a heavier
integration; the owner chose the simpler collection path for the early phase and to
build the balance/payout ledger in-house.

**Cost, stated plainly:** the platform is holding money that belongs to sellers until
payout. At scale that is the activity of a **payment/e-money institution** and draws
BDDK licensing obligations in Turkey. This is an accepted early-phase trade; the
migration to a submerchant settlement is a **future ADR**, and the balance ledger
(§7) is deliberately shaped so that migration changes who moves the money, not how the
platform accounts for it.

---

## 3. The PayTR integration (ADR-060)

PayTR is integrated in its **iFrame API** shape: **no card data ever touches the
platform.** The buyer's card and its 3-D Secure step happen entirely inside PayTR's
iframe; the platform only ever sees a result.

The whole PSP surface lives behind **`PaymentGatewayContract`** so the domain never
names PayTR:

> **Amended 2026-08-04 (P1 build, reported for ratification).** The spec placed
> this interface in `app/Core`. It could not go there: its signatures are
> Payment's own DTOs, and `LayeringTest` enforces "Core never depends on a
> module" — a rule that outranks a module spec in the document chain (CLAUDE.md →
> ADR → 001 → … → module specs). The three ways out were to move the DTOs into
> Core, retype the port on plain arrays, or put the interface where its vocabulary
> already lives. **It lives at
> `App\Modules\Payment\Domain\Contracts\PaymentGatewayContract`.**
>
> The reason given below is fully preserved — the domain still never names PayTR,
> because the actions depend on the interface and `PayTrGateway` is bound to it in
> the service provider. The Core placement was never load-bearing: every contract
> in `app/Core/Domain/Contracts` exists so one MODULE can ask ANOTHER a question
> without importing it, and this port points *out of the platform* at a payment
> provider that no other module will ever call. It is the same kind as
> `CategorySlugGeneratorContract`, which lives in Catalog for the same reason.

```
interface PaymentGatewayContract {
    initiate(PaymentIntentDTO): GatewaySessionDTO;   // → a token/URL for the iframe
    verifyCallback(raw): GatewayResultDTO;            // hash-checked, provider-shaped in
    refund(PaymentRefundDTO): GatewayRefundResultDTO;
}
```

`PayTrGateway` (Infrastructure) is the only implementation. A second PSP later is a
second adapter, not a change to the domain.

### The flow

```
Order (already): checkout split the basket into one order per seller under a
                 checkout_group, RESERVED the stock, left them awaiting_payment.

1. initiate(checkout_group)
      → Payment aggregate created (state: pending), amount = Σ orders' grand_total
      → PaymentGateway.initiate builds PayTR get-token request:
          merchant_oid = payment.uuid   (our idempotency key)
          payment_amount = total in KURUŞ (minor units — PayTR's unit is ours)
          user_basket, email, 3DS on, installments per §8, test_mode flag,
          hash = HMAC over the fields with merchant_key + merchant_salt
      → returns the iframe token
2. Storefront embeds PayTR's iframe with that token. Card + 3DS happen there.
3. PayTR → our CALLBACK url (server-to-server, NOT the browser):
      merchant_oid, status (success|failed), total_amount, hash
      - verifyCallback re-computes the hash and REJECTS a mismatch.
      - IDEMPOTENT: the same merchant_oid may arrive more than once (PayTR retries
        until it gets "OK"); process once, always answer "OK" (plain text) or the
        retries never stop.
4a. success → Payment=paid → for EVERY order in the group:
        confirm (awaiting_payment → paid/confirmed)
        Inventory COMMIT the held reservation (ADR-057 — placement only held it)
        compute + freeze commission (§6), credit seller balance (§7)
    then PayTR redirects the buyer's browser to the success page.
4b. failed → Payment=failed → RELEASE the reservations (Inventory) → the orders go to
        a failed/cancellable state; the basket can try again.
```

**The callback is the source of truth, not the browser redirect.** A buyer who closes
the tab after paying must still have their order confirmed; the server-to-server
callback is what does it. The redirect only decides what the buyer *sees*.

**One payment, many orders.** The buyer pays once for the whole basket, so the Payment
aggregate is keyed to the **checkout_group**, and success/refund fan out to all its
orders together. This is the mirror of ADR-052's split: Order split the basket to ship
it; Payment rejoins it to charge it.

---

## 4. The Payment aggregate & states

One Payment per checkout_group. States:

```
pending ──▶ paid ──▶ (refunded | partially_refunded)
   │
   └──▶ failed        (also: expired if the buyer never completes the iframe)
```

`pending → paid` is driven **only** by a hash-verified success callback. Every
transition is append-only audited (the platform already audits; money doubly so).
Public id is the uuid; the internal id never leaves. `merchant_oid = uuid` ties our
record to PayTR's without exposing anything internal.

---

## 5. Stock commit on payment success — closing ADR-054/057

ADR-054 first said placement commits the stock; ADR-057 amended it to **placement
only HOLDS the reservation**, and named Payment as the module that commits. This
module is that caller.

On the success callback, for each order in the group, Payment calls Inventory's
**reservation commit** through the same Core command port Order already drives —
turning the held reservation into a permanent stock decrement. On failure/expiry it
**releases** instead. Payment never touches a stock number itself; it only commands
the reservation it was handed, keyed by Order's `order_uuid:variant_uuid` reference
(the string key from the reservation-uuid fix, not a uuid — the trap this platform
has now met four times).

---

## 6. Commission — a multi-dimensional rule engine (ADR-061)

Commission is **not** one platform rate. The owner sets **different commissions by
product, category, brand and seller** — any of them, in any combination.

### The table

`commission_rules` (a lookup/config table — an operator adds a rate without a release,
so it is a **table**, not an enum; ADR-015 `is_active`):

| column | meaning |
|---|---|
| `seller_org_uuid` | nullable — this seller, or any |
| `product_uuid` | nullable — this product, or any |
| `brand_uuid` | nullable — this brand, or any |
| `category_uuid` | nullable — this category (subtree), or any |
| `rate` | `DECIMAL` — the percentage |
| `priority` | integer — explicit tiebreak / override |
| `is_active` | lookup-table convention |

A rule with **all four scopes null** is the **platform default**.

### Resolution — most-specific-wins

An order line knows its product, brand, category and seller. For that line:

1. Take every **active** rule whose every **non-null** scope matches the line (a null
   scope is a wildcard that always matches).
2. Rank by **specificity** = how many scopes are set (4 beats 1).
3. Break ties by explicit **`priority`** (higher wins), then most-recent.
4. If nothing matches beyond it, the **platform default** applies.

So "seller X + category Kozmetik = 12%" beats "brand Bioderma = 10%" beats
"category Kozmetik = 15%" beats "default = 18%", for a line that matches all of them —
because it is the most specific. The operator composes rates by adding rows.

### The base — KDV-**inclusive** sale amount (owner choice 2026-08-04)

Commission is `rate × the line's KDV-INCLUSIVE sale total` (the gross the buyer paid),
in integer kuruş. Not ex-KDV. (The KDV on the commission itself, and the commission
invoice the platform owes the seller, are accounting/e-fatura concerns outside this
software.)

### Frozen at payment

The resolved rate and the computed commission kuruş are **snapshotted onto the order's
lines at payment time** — like the price/tax snapshot Order already freezes (ADR-053).
Changing a rule later re-prices the **next** sale, never a settled one. A commission a
seller already saw deducted must never move.

---

## 7. Seller balance — an append-only ledger (ADR-062)

What a seller is owed is a **ledger**, not a mutable balance column — the same
append-only discipline as Audit and the Inventory movement ledger. Balance is the sum
of its entries, computed on read.

`seller_ledger_entries` (append-only; the model refuses update/delete):

| field | |
|---|---|
| `seller_org_uuid` | whose balance |
| `type` | `sale_credit` \| `commission_debit` \| `payout_debit` \| `refund_debit` \| `refund_commission_credit` |
| `amount_minor` | integer kuruş (signed by type) |
| `order_uuid` / `payment_uuid` / `payout_uuid` | what produced it |
| `created_at` | |

On a paid order, per seller: a **`sale_credit`** of the order's KDV-inclusive total
and a **`commission_debit`** of the commission — so the seller's balance rises by
**net of commission**. Payout and refund append their own debits (§8). Balance =
`Σ amount_minor`. Never a stored number to drift.

---

## 8. Payout & refunds

**Payout (manual/batch).** An admin creates a **Payout** for a seller for an amount up
to their available balance; it appends a `payout_debit`. Payout has its own small state
machine (`pending → paid`/`failed`) and records the external reference of the bank
transfer the platform actually made. The software does **not** move the money — it
records that a human/bank did (single-merchant model, §2). A payout cannot exceed the
computed balance; concurrent payouts are guarded so a balance cannot go negative.

**Refund.** A refund (admin, or a customer-cancel that the policy allows) calls
`PaymentGateway.refund` (PayTR's iade API), and on success:

- reverses the money to the buyer through the PSP,
- appends `refund_debit` (remove the sale credit) **and** `refund_commission_credit`
  (give back the commission the platform took) to the seller's ledger,
- **restocks** through Inventory (the mirror of the commit in §5),
- moves the Payment/order to `refunded` / `partially_refunded`.

**Refund vs payout is the ordering hazard** and the ledger is what makes it safe:
because balance is a sum of entries, a refund after a payout simply drives the balance
negative and blocks the next payout until it is made whole — the money is never lost
track of, which a mutable balance column could not promise.

---

## 9. Boundaries & non-negotiables (Payment-specific)

- **Money = integer kuruş everywhere;** `DECIMAL` only for the commission/tax **rate**.
  APIs format money as decimal strings. (ADR-005.)
- **No card data, ever** — PayTR iframe holds it; the platform stores a PSP reference
  and the result, nothing more. Entering card details is prohibited by the platform's
  own rules regardless.
- **The success callback is idempotent and hash-verified;** a spoofed or replayed
  callback must change nothing.
- **Append-only** Payment audit, seller ledger, payout records.
- **Imports no module;** Core contracts + class-string events + the gateway port only.
  `LayeringTest` enforces it.
- **`current_actor()` / named guards**, never `auth()->user()`. Side effects (PSP
  calls that are safe to repeat, mail) after commit via `BaseAction::after()`.

## 10. Internal phases (built in order)

- **P1 — Collection core.** `PaymentGatewayContract` + `PayTrGateway`, the Payment
  aggregate (checkout_group), initiate + get-token, the hash-verified idempotent
  callback, success → confirm orders + **Inventory commit**, failure → release. *End
  of P1: "ödeme çalışıyor" — the ADR-054/057 promise is kept.*
- **P2 — Commission engine.** `commission_rules` + most-specific-wins resolution +
  KDV-inclusive base + snapshot onto order lines at payment.
- **P3 — Seller ledger.** Append-only `seller_ledger_entries`; sale_credit +
  commission_debit on paid orders; balance-on-read.
- **P4 — Payout.** Admin batch payout resource + state machine + the balance guard.
- **P5 — Refund.** Gateway refund + ledger reversal + Inventory restock + state.

## 11. Deliberately absent / follow-ups

- **Submerchant/marketplace settlement** — the licensing-clean model; a future ADR
  (§2). The ledger is shaped to make that migration additive.
- **e-fatura / commission invoicing** — commission produces a number; issuing the
  legal invoice is out of software scope.
- **Payout automation** (bank API) — v1 is manual/batch with a recorded reference.
- **Installments (taksit) economics** — PayTR supports taksit; v1 passes it through
  (buyer may choose taksit) but does not model the vade-farkı split. A follow-up.
- **Wallet / store credit, partial captures, subscriptions** — out of scope.

## 12. What this requires of other modules (read-only, contract-level)

- **Order** — Core read of a checkout_group's orders + their line snapshots (amounts,
  KDV, seller), and the existing reservation-commit command port. No Order import.
- **Catalog** — the line already carries product/brand/category ids for commission
  resolution (snapshotted at order time); Payment reads them off the order, not the
  live catalogue.
- **Organization/Store** — seller org uuid for the ledger; read by id only.

None of these is a new cross-module dependency: all go through Core, exactly as
Offer/Inventory/Order do.
