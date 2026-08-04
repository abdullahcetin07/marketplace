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

## Phases

P1 collection core ✅ · P2 commission engine · P3 seller ledger · P4 payout ·
P5 refund.
