# Payment

Takes the money. Turns a placed-but-unpaid checkout group into a collected
payment, **commits the stock that placement only held** (ADR-057 — this module is
the caller that decision named), and from P2/P3 splits the money into what the
platform keeps and what each seller is owed.

Full specification: [docs/modules/Payment.md](../../../docs/modules/Payment.md).
Decisions: ADR-060 (settlement + PayTR + commit-on-success), ADR-061 (commission),
ADR-062 (seller ledger + payout).

## The shape

```
Order:    checkout split the basket per seller, RESERVED stock, left them
          awaiting_payment.

initiate  → one Payment per checkout_group (pending), amount summed from the
            orders through OrderQueryContract → PSP token → the storefront
            embeds the iframe.
callback  → hash-verified, idempotent, one transaction:
              success → paid + Inventory COMMIT + PaymentSucceeded
              failure → failed + Inventory RELEASE + PaymentFailed
          → always answers "OK" in plain text.
```

## What bites

- **A balance and a payable amount are two different numbers** (S3, ADR-064). Money
  from an order that has not been delivered long enough is OWED and not drawable —
  a seller must not be paid for goods the buyer can still send back. `SellerBalance`
  reports balance / on-hold / payable together so no screen can show one and
  enforce another. An order with no delivery window is HELD; a ledger entry with no
  order is never held.
- **The delivery windows are frozen columns.** Editing `shipping.payout_hold_days`
  governs the next delivery, never one already recorded.
- **Payouts are proposed automatically, daily, one per seller** — and a human still
  makes the bank transfer. `created_by` null means the schedule decided.
  **The scheduler must be running** or no payout is ever proposed and, worse, no
  delivery is ever inferred upstream.

- **Money is integer kuruş, everywhere.** PayTR's unit is the platform's unit, so
  the integer travels end to end and no float is ever constructed. The only
  decimals in the module are display strings and PayTR's refund field, both built
  with `intdiv`.
- **The callback is the source of truth, not the browser redirect.** A buyer who
  closes the tab after paying still gets their order.
- **It always answers `"OK"`.** PayTR retries anything else for days. Whether the
  payment was accepted, rejected as forged or recognised as a retry lives in the
  audit trail, not in the status code.
- **Idempotent by the Payment uuid** = PayTR's `merchant_oid`. The same callback
  arrives many times; it settles once.
- **No card data exists in this module.** No column, no DTO field, no log line.
- **It imports no module.** Orders via `OrderQueryContract`, the stock commit via
  `InventoryReservationContract`, the PSP via `PaymentGatewayContract`. Order
  learns money arrived by subscribing to `PaymentSucceeded` **by class-string**
  from its own side — Payment never sets an order's status.

## The commission engine (P2)

`commission_rules` has four nullable scopes — seller, product, brand, category —
and **null means "any"**. The row with all four null is the platform default: not
a special kind of row, just the least specific one there is.

**Most-specific-wins.** The rule that sets the MOST scopes takes the line.
`priority` breaks a tie between rules of EQUAL specificity and **can never beat
specificity itself** — a priority that could would make "why did this line get
12%?" unanswerable without simulating the engine.

**A category rule covers its subtree**, matched against the ancestry snapshotted
on the line rather than the live tree.

**Two snapshots, two moments.** The classification (brand, category, ancestry) is
frozen at checkout because it is what the rules match; the commission (rate,
kuruş) is frozen at payment because that is when money moves. A rate edited in
between applies; one edited afterwards does not.

**Base = the KDV-INCLUSIVE line total** — the gross the buyer paid. One half-up
rounding helper (`CommissionAmount`) serves charge and refund, because a kuruş of
disagreement between them drifts a seller's balance forever.

Payment computes; **Order writes**. `order_lines` is Order's aggregate, so the
answer crosses through the Core `CommissionQueryContract` and Order's own
`PaymentSucceeded` listener does the writing.

## The seller ledger (P3)

**There is no balance column anywhere**, and that absence is the decision: a
stored balance is a number that can drift from the events that produced it, and
the first time it does nobody can tell which is right. Balance is
`Σ amount_minor`, computed on read.

A paid order appends **two rows per seller** — `sale_credit` of the order's
KDV-inclusive total and `commission_debit` of the frozen commission. Two rows and
not one net figure, because "you earned 120,00 and we took 21,60" is a sentence a
merchant can check.

**The sign lives on the type.** Credits store positive, debits negative, so a
balance is a plain `SUM()` — and callers pass a MAGNITUDE, so no call site can
append a positive commission and pay the seller the platform's cut.

**Append-only, with no escape hatch at all** — not even the narrow once-only one
`OrderLine` has. A correction to money is a new entry.

**It reads the frozen commission**, never resolving the rules a second time: two
computations of one number is how the ledger and the order stop agreeing. A null
commission makes it skip and log rather than guess.

## Payouts (P4)

**The software moves no money.** A `Payout` records that an admin decided to send
a seller their balance, and later that a human or a bank did — with the reference
they were given. There is no banking integration and v1 does not want one.

**The debit lands when the payout is CREATED**, not when it is marked paid. If the
balance only moved at `paid`, two admins could each create a payout for the whole
balance, both pass their check, and the seller be overdrawn when both went
through.

**A rejected transfer gives the balance back** with a `payout_reversal_credit` —
a sixth ledger type ADR-062 does not list, added for this and **reported for
ratification**. The ledger is append-only, so the debit cannot be deleted.

**The guard is a row lock on the seller's ledger** taken before the balance is
read: a `SUM` cannot be locked, but the rows it sums can.

**Append-only in its money, a state machine in its outcome.** Amount, seller and
currency never change; only the six outcome fields, and only out of `pending`.
Never deleted — a mistake is marked failed.

Admin-only: `GET/POST /admin/payouts`, `POST /admin/payouts/{uuid}/settle`,
`GET /admin/sellers/{uuid}/balance`, plus a Filament screen showing the live
balance beside the amount field.

## Phases

P1 collection core ✅ · P2 commission engine ✅ · P3 seller ledger ✅ ·
P4 payout ✅ · P5 refund.
