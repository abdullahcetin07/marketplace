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

## Phases

P1 collection core ✅ · P2 commission engine ✅ · P3 seller ledger ✅ · P4 payout ·
P5 refund.
