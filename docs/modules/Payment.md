# Payment

**Status: COMPLETE (2026-08-04; P1–P5 built, ADR-060–062).** The spec below is the
approved architecture; the P1/P3/P6 amendment notes inline record where the build
refined it. Two open follow-ups: refund is admin-only in v1 (the policy-allowed
customer cancel waits on Shipping/fulfillment status — the action already takes an
actor id and only `PaymentPolicy::refund()` changes when it arrives); and the admin
commission-rule form takes scopes as UUIDs (a nicer seller/category picker needs a
Core port or a Presentation seam, since Payment imports no module). One remaining
server step: bind the new `payment.refund` permission to the Admin role in
`RolePermissionSeeder` (Super Admin bypasses it already).

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
3. PayTR → our CALLBACK url (server-to-server, NOT the browser; the address is a
   PANEL setting, see below):
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

> **As built (2026-08-05): PayTR is told the callback address in ITS PANEL, not by
> the API.** The iFrame API has no notification-URL parameter — `merchant_ok_url`
> and `merchant_fail_url` only decide where the BROWSER lands afterwards. The
> callback address is **Mağaza Paneli → Destek & Kurulum → Ayarlar → Bildirim URL**,
> and it must be `https://<host>/api/v1/payments/paytr/callback`.
>
> **Getting it wrong is invisible from this application.** The money is taken, the
> buyer sees the success page, and the order stays `awaiting_payment` forever
> because the callback — not the redirect — is what settles it. Nothing is logged
> here, because nothing arrives here. The evidence is in nginx: PayTR retries
> roughly once a minute, from its own IP, against whatever wrong path is
> configured. `config('payment.paytr.notification_url')` records the correct value
> so it is reviewable in the repository, and `php artisan payment:diagnose` prints
> it beside the credential check and the count of payments stuck pending.
>
> **The route is CSRF-exempt** (`bootstrap/app.php`), because a PSP cannot carry a
> token: it posts from its own network with no browser and no session. That is not
> a hole — the endpoint authenticates the SENDER by recomputing PayTR's HMAC with
> the merchant key, which is strictly stronger than a token any cookie-bearing
> browser would have supplied. Sanctum's stateful shortcut happens to skip CSRF for
> a request without an Origin/Referer, so PayTR was never blocked in practice — but
> that is a header a third party controls, and settlement must not depend on it.
> `tests/Feature/Payment/CallbackCsrfTest.php` pins both the exemption and the fact
> that it is one path and not a wildcard; it drives the middleware directly, because
> `ValidateCsrfToken` disables itself for the whole test suite and a feature test
> asserting a 200 here proves nothing.

> **As built (2026-08-05): the uuid travels WITHOUT ITS HYPHENS.** The real API answers
> `merchant_oid alfanumerik olmalidir, ozel karakter iceremez` — a uuid's hyphens are
> special characters, so every live get-token call was refused while the suite stayed
> green against a mock that accepts anything. That is the standing limit of testing an
> adapter against a fake, and it is why the fixture in that test is now a real uuid.
>
> The decision is unchanged: **one identifier, ours**. The 32 hex digits are the same
> uuid, losslessly and reversibly, so no second `merchant_oid` column exists to disagree
> with `payments.uuid`. `PayTrGateway::merchantOid()` strips on the way out and
> `referenceFrom()` restores on the way in, so nothing outside that class has ever heard
> of PayTR's identifier format — and an oid it does not recognise passes through
> untouched, because a payment created before the fix carries hyphens on PayTR's side and
> its callback must still resolve.

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

> **As built (P2, 2026-08-04).** Two snapshots at two moments, and the split matters:
>
> - **The classification** (`brand_uuid`, `category_uuid`, `category_path_uuids`) is
>   frozen at **checkout**, because it is what the rules are matched against. A product
>   re-categorised next month must not change which rule applied to a sale already made.
>   The ancestry travels with it so the subtree test is a membership check on the line
>   itself, never a walk of the live tree.
> - **The commission** (`commission_rate`, `commission_minor`, `commission_resolved_at`)
>   is frozen at **payment**, because that is when money changes hands. A rate edited
>   between placing and paying should apply; one edited afterwards must not.
>
> **§12's claim that "the line already carries product/brand/category ids" was not
> true** — `order_lines` held only `product_uuid`. The three classification columns were
> added for this, Order-owned, the same shape ADR-055 used when Catalog gained
> `tax_rate_id` for Order. Lines placed before the migration carry nulls and simply fall
> through to a less specific rule, which is honest rather than back-filled from a
> taxonomy that has since moved.
>
> **ORDER WRITES THE SNAPSHOT; PAYMENT COMPUTES IT.** `order_lines` is Order's
> aggregate, so Payment answers through a Core `CommissionQueryContract` and Order's
> existing `PaymentSucceeded` listener does the writing — the same reason Payment
> announces rather than setting an order's status (P1).
>
> **`OrderLine` is immutable and stays that way.** Its `updating` guard now permits
> exactly one deferred write: a change touching *only* the three commission columns,
> and only while `commission_resolved_at` is null. So a retried callback, a later rule
> change and a direct `update()` are all refused — which is the ADR's sentence enforced
> in code rather than by convention.

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

> **As built (P3, 2026-08-04).**
>
> **The sign lives on the type, not at the call site.** A `sale_credit` is stored
> positive and a `commission_debit` negative, so a balance is a plain `SUM()`
> rather than a `CASE` ladder that must know what every type means. Callers pass a
> MAGNITUDE and `LedgerEntryType::signedAmount()` points it — once, for everybody —
> so no call site can append a positive commission and pay the seller the
> platform's cut.
>
> **All five types are declared now**, unlike `OrderStatus`, which withheld cases
> nothing could set. The reason differs: what is being defined here is the SIGN
> CONVENTION, and it has to be complete to be coherent — a refund reverses a sale,
> and saying so now is what makes P5's direction obvious rather than a decision
> taken under deadline.
>
> **The ledger READS the frozen commission; it does not recompute it.** Order froze
> it onto the lines a moment earlier (ADR-061), and reading it back is what
> guarantees the two agree to the kuruş forever. Two computations of one number is
> exactly how they stop agreeing.
>
> **Which means it depends on Order's listener having run** — both subscribe to
> `PaymentSucceeded`, and `OrderServiceProvider` boots first. The ledger listener
> does not TRUST that: a null commission makes it **skip and log**, because
> crediting a full sale with no commission taken silently overpays a merchant and
> the platform finds out at payout. A missing pair of entries is recoverable; a
> wrong one is an argument.
>
> **Idempotent twice over.** PayTR retries until it hears "OK", so the listener
> skips what is already recorded AND `(payment_uuid, order_uuid, type)` is UNIQUE,
> so a race loses rather than double-crediting.
>
> **It runs after commit**, since the event fires from `BaseAction::after()` — an
> entry for a payment a later failure rolled back would be an accounting record of
> money that never arrived. The mirror-image cost: a failure here leaves a
> collected payment uncredited, which is why it logs loudly and why every entry is
> rebuildable from the order it names.
>
> **`SellerLedgerEntry` has no escape hatch at all** — not even the narrow,
> once-only one `OrderLine` has. A line's commission is genuinely decided later;
> every field of a ledger row is known when it is written, so an edit could only be
> a correction, and a correction to money is a new entry.

---

## 8. Payout & refunds

**Payout (manual/batch).** An admin creates a **Payout** for a seller for an amount up
to their available balance; it appends a `payout_debit`. Payout has its own small state
machine (`pending → paid`/`failed`) and records the external reference of the bank
transfer the platform actually made. The software does **not** move the money — it
records that a human/bank did (single-merchant model, §2). A payout cannot exceed the
computed balance; concurrent payouts are guarded so a balance cannot go negative.

> **As built (P4, 2026-08-04).**
>
> **The debit lands at CREATION, not at `paid`** — the non-obvious choice, and the
> one the guard rests on. If the balance only moved when a payout was marked paid,
> two admins could each create a payout for the whole balance, both would pass
> their own check, and the seller would be overdrawn when both transfers went
> through. Committing the balance when the DECISION is made closes that window;
> marking it paid then changes no balance at all.
>
> **Which forces a sixth ledger type — reported for ratification.** ADR-062
> enumerates five, and a rejected transfer needs a way to give the money back: the
> ledger is append-only so the debit cannot be deleted, and none of the five means
> "that payout did not happen". `payout_reversal_credit` is added for it. The
> alternative shape — debiting only at `paid` — was rejected for the overdraw
> above, and reusing `refund_commission_credit` would put a refund in the balance
> history of a sale nobody refunded.
>
> **The concurrency guard is a row lock on the seller's own ledger**, taken before
> the balance is read, inside the action's transaction. A `SUM` cannot be locked
> but the rows it sums can, so two simultaneous payouts for one seller must lock
> the same rows and the second reads the reduced balance. Its limit, stated: a
> seller with no ledger rows has nothing to lock — and a zero balance, so there is
> no payout to race over.
>
> **A payout is append-only in its money and a state machine in its outcome.**
> `amount_minor`, `seller_org_uuid` and `currency_id` can never change, because the
> ledger already debited them; the guard permits ONLY the six outcome fields and
> only out of `pending`, so a settled payout is final and slipping `amount_minor`
> into the same call fails the whole write. Same narrow-hole shape as `OrderLine`'s
> commission, same reason.
>
> **Never deleted.** A mistaken payout is marked FAILED, which reverses the debit
> and leaves both facts on the trail — deleting would erase a transfer somebody may
> actually have made.
>
> Admin-only throughout: `GET/POST /admin/payouts`,
> `POST /admin/payouts/{uuid}/settle`, `GET /admin/sellers/{uuid}/balance`, plus a
> Filament screen that shows the live balance beside the amount field. There is no
> seller-facing payout surface in v1 — a merchant sees their balance.

**Every PSP refusal is written to the `errors` log verbatim (2026-08-05).** The
refusals in this module are non-reportable domain exceptions — a declined basket is not
an incident — and the consequence was that a merchant-configuration failure produced a
422 to the buyer and nothing anywhere else: the platform took no money and nobody could
say why. `PayTrGateway::logRejection()` now records PayTR's own `reason` beside the
request fields that decide whether the hash could have matched. **`merchant_key` and
`merchant_salt` never appear in it** — anyone holding them can forge a "payment
succeeded" this platform would believe — and the buyer's e-mail is masked.

The exception carries it too, in two different strings: `getMessage()` holds the
provider's verbatim refusal (what a stack trace and a `report()` actually contain) while
`userMessage()` resolves the translation from the `reason` in the context. A shopper
never reads PayTR's operator-facing words.

**Refund.** A refund (admin, or a customer-cancel that the policy allows) calls
`PaymentGateway.refund` (PayTR's iade API), and on success:

- reverses the money to the buyer through the PSP,
- appends `refund_debit` (remove the sale credit) **and** `refund_commission_credit`
  (give back the commission the platform took) to the seller's ledger,
- **restocks** through Inventory (the mirror of the commit in §5),
- moves the Payment/order to `refunded` / `partially_refunded`.

> **As built (S3, 2026-08-05): payout waits on DELIVERY, not on payment.**
>
> This is what ADR-060 deferred when it left payout manual-only. Payment subscribes
> to Shipping's `ShipmentDelivered` **by class-string** — neither module names the
> other — and freezes two dates in a `settlement_windows` row keyed on the order:
> `payout_eligible_at = delivered_at + settings('shipping.payout_hold_days')` and
> `return_window_ends_at = delivered_at + settings('shipping.return_days')`.
>
> **A BALANCE AND A PAYABLE AMOUNT STOPPED BEING THE SAME NUMBER.** `SellerBalance`
> reports three: the balance (Σ of the ledger, unchanged), what is **on hold**
> (the net of orders not yet delivered long enough), and what is **payable** —
> which is now the ceiling `CreatePayoutAction` enforces. A seller must not be paid
> for goods the buyer can still send back, or the platform is recovering money it
> has already handed over.
>
> **An order with no window is HELD, not payable.** That is the conservative half
> and the important one: an order paid but never delivered has no row at all, and
> reading "no window" as payable would pay the seller the moment the card cleared —
> exactly what ADR-064 exists to prevent. The mirror rule: a ledger entry with **no
> `order_uuid`** is never held, because an adjustment cannot be tied to a parcel and
> holding it would freeze money with nothing that could ever release it.
>
> **The dates are frozen, not derived on read.** An operator shortening the hold
> must not make last month's deliveries retroactively payable, nor lengthening it
> withdraw a payout a seller was already promised — the same discipline as an order
> line's price (ADR-053).
>
> **`delivered_at` comes off the EVENT**, never the consuming clock: a listener
> running an hour behind must not push a seller's payday an hour out.
>
> **The manual payout stays**, and it is now joined by an automatic one.
>
> **As built (owner decision, 2026-08-06): the DECISION is automated too.**
> `CreateDuePayoutsJob` runs daily and proposes **one pending payout per seller**
> for their whole payable balance — every order whose hold has expired, summed by
> the ledger. S3 had shipped eligibility only and left the decision to an admin;
> the owner chose to automate it.
>
> **ONE PER SELLER, NOT ONE PER ORDER.** A payout is a bank transfer somebody
> executes by hand, so per-order would make the finance team's work proportional to
> the platform's order count.
>
> **THE BANK IS STILL NOT AUTOMATED.** The job writes a `pending` row; a human
> makes the transfer and marks it paid through the existing settle flow. ADR-062's
> "the software moves no money" is untouched.
>
> **`created_by` IS NULL WHEN THE SCHEDULE DECIDED IT** — an absent actor rather
> than a synthetic "system" user, because an account nobody owns with the authority
> to move money is an account somebody eventually logs into. `Payout::isAutomatic()`
> is how the panel tells the two apart, and the payout table filters on it.
>
> **THREE THINGS STOP A DOUBLE PAYOUT**, only the first of which is the job's own:
> a seller with a PENDING payout is skipped (two open transfers for one seller is a
> reconciliation problem whatever the arithmetic says); creating a payout appends
> `payout_debit` immediately, so the payable balance falls to zero; and the action's
> row lock serialises overlapping runs. Running the job more often than daily is
> harmless, because somebody eventually will.
>
> It writes no rows of its own — it calls `CreatePayoutAction`, so the lock, the
> payable ceiling and the debit are the same code a manual payout uses. A job that
> inserted `payouts` directly would be a second, quieter path to moving money.

**Refund vs payout is the ordering hazard** and the ledger is what makes it safe:
because balance is a sum of entries, a refund after a payout simply drives the balance
negative and blocks the next payout until it is made whole — the money is never lost
track of, which a mutable balance column could not promise.

> **As built (P5, 2026-08-04).**
>
> **A refund names ORDERS, not an amount.** `partially_refunded` on this platform means
> some of the SELLERS' ORDERS in the basket — the ADR-052 split seen from the refund
> side. An arbitrary lira figure could say none of the three things a refund has to
> know: which seller it comes out of, which commission to give back, which units to
> restock.
>
> **The PSP goes first, inside the transaction.** Nothing is written until PayTR agrees.
> Writing the ledger first would leave a seller debited for a refund that never
> happened, and unlike a payment there is no callback coming later to correct it. Its
> cost, stated: a slow PSP holds a transaction open — bounded because the rows involved
> are this payment's own and nothing else contends for them.
>
> **`payment_refunds`, one row per (payment, order), append-only, no hole at all.**
> `Payout` needed a narrow writable hole because a bank's answer arrives later; a refund
> row is written only after the provider has already said yes, so there is no fact left
> to learn. What is refunded is **Σ of these rows**, never a column — the same rule as
> the balance. The unique `(payment_id, order_uuid)` index is the real guard: this is
> the one operation in the module a human triggers by clicking, so it will be clicked
> twice, and there is no retry semantics to lean on.
>
> **Inventory's command port gained a fourth verb, `restock`** (amends ADR-049). It
> raises `on_hand` and leaves `reserved` alone — the hold ended when the sale completed
> and does not come back — and it is a no-op on anything not `committed`, which is what
> stops a retried refund inventing stock that does not physically exist. It is
> deliberately not `release` called late: Order.md §12.5 ruled that reversing a sale and
> abandoning a hold are different business events, so it has its own movement type
> (`restocked`), terminal reservation state and timestamp. **This closes that
> follow-up.**
>
> **Refunding is ADMIN-ONLY in v1 — a stated narrowing of the paragraph above.** The
> "customer-cancel that the policy allows" half cannot be judged yet: whether a customer
> may reverse their own purchase depends on whether it has SHIPPED, and there is no
> fulfilment state on this platform. A self-serve refund button that cannot tell "cancel
> before dispatch" from "return after delivery" would be granting a business rule nobody
> wrote down. `RefundPaymentAction` takes an actor id and does not care what type of user
> it is, so when Shipping ships, only `PaymentPolicy::refund()` changes.
>
> `POST /admin/payments/{uuid}/refund` (orders + reason, no amount),
> `GET /admin/payments/{uuid}/refunds`, plus a read-only Filament screen whose single
> action is the refund — the only button in the panel that sends money out, and the only
> one behind a confirmation that says so.

> **As built (S4, 2026-08-06): the buyer's own return, and a refund that names LINES.**
>
> P5 refunded whole orders and said what it was waiting for: a fulfilment state to
> judge a customer-initiated refund by. Shipping supplied one — a delivery date and a
> return window (ADR-064, S3) — and S4 is both halves of what that unlocked. **It
> closes §11's "customer-facing refund" and "partial refund of a single order"
> follow-ups.**
>
> **A refund still names no amount. It names LINES AND QUANTITIES.** P5's rule one
> level down: the buyer says what is going back in the box — one of the two shoes —
> and the platform prices it from the frozen line snapshot. A lira figure from a
> client would be a client deciding what its own return is worth.
>
> **The KDV needs no separate term, and that is the part worth reading.** Turkish
> retail prices are KDV-INCLUSIVE (ADR-055), so `unit_price_minor` already contains
> the tax and refunding `unit_price × qty` refunds the tax with it, in exactly the
> proportion it was charged. A "proportional KDV" line on top would give the buyer
> the tax twice.
>
> **The commission is the FROZEN figure SCALED, never the rules re-resolved**
> (ADR-061) — `commission_minor × qty ÷ line quantity`, half-up, in integer
> arithmetic with no float constructed anywhere (ADR-005). Re-running the resolver
> would apply today's rates to last month's sale, and charge and reversal would
> disagree by a kuruş on exactly the orders somebody checked.
>
> **THE LAST UNIT OF A LINE TAKES THE REMAINDER.** A refund that empties a line is
> billed as "everything not yet refunded" rather than as a multiplication, because
> `line_total` is not always `unit_price × quantity` to the kuruş once a rounding has
> happened upstream — and without this a fully returned line would strand a kuruş
> forever. Same trick a well-behaved instalment plan uses for its final payment.
>
> **P5'S UNIQUE INDEX HAD TO GO, AND THE GUARANTEE GOT WEAKER.** `payment_refunds`
> was UNIQUE on `(payment_id, order_uuid)` and `seller_ledger_entries` on
> `(payment_uuid, order_uuid, type)`; a line-level refund makes a SECOND refund of one
> order legitimate — one shoe today, the other next week — so both were dropped for
> non-unique indexes. What replaced them is arithmetic: a line may go back up to its
> REMAINING quantity, summed from `payment_refund_lines`. **A constraint cannot be
> forgotten and a sum can.** Stated rather than glossed: the check lives in exactly one
> place (`RefundableLines`) and the compensating cover is the idempotent
> double-callback case in `PaymentCollectionTest`, which still asserts one credit after
> two callbacks. Asking for three of two is a REFUSAL, not a clamp.
>
> **Inventory's `restock` became quantity-aware** (amends ADR-049 a second time, after
> P5 added the verb). `restock($reference, $quantity)` puts back the units that came
> back; `null` still means all of it, so P5's callers are unchanged. Idempotence stopped
> being a status check and became `restocked_quantity` against the reservation — the
> reservation stays `committed` until the last unit is home. Asking for more than is
> still out there returns what is left, never more: an inflated restock invents stock
> that does not physically exist and the seller sells it to somebody.
>
> **A parcel becomes `returned` only when the ORDER is fully back.** `PaymentRefunded`
> names the order exactly when every unit of every line has gone back, and Shipping's
> class-string listener moves the shipment. A buyer who kept one of two shoes has a
> parcel that was still delivered. Payment does not touch a shipment, any more than it
> touches an order's status.
>
> **Two doors to one machine.** `RequestReturnAction` is the buyer's — it checks
> ownership through the Core Order port, that a settlement window exists (only a
> delivery creates one), and that it is still open — and then calls
> `RefundLinesAction`, which is the money. An admin gets the same action without those
> three checks, because a refund outside the window is exactly the judgement call the
> window hands back to a human. One implementation of the arithmetic, or the two
> disagree the day either changes. **Every refusal answers the same** — "not yours",
> "never delivered", "too late", "no such order" — or the error itself tells a prober
> which one it was.
>
> **The admin needed the line path too, and that is S4 cleaning up after itself.** The
> whole-order path SKIPS an order that already has a refund row — correctly, or it
> would refund the returned unit twice — so an order a buyer partly returned would have
> been stuck partly refunded forever.
>
> `GET /orders/{uuid}/return` (what may still go back, until when, and what the
> platform will pay for it), `POST /orders/{uuid}/return` (lines + quantities +
> reason), and `order_id` + `lines` on the existing
> `POST /admin/payments/{uuid}/refund`. The GET exists because a storefront
> multiplying `unit_price × quantity` itself would disagree with the last unit's
> remainder.

> **As built (Cancellation C1, 2026-08-06): the other side of "shipped".**
>
> ADR-065 is the mirror of the return, and the point is how little is new. A paid
> order whose parcel has NOT LEFT can be cancelled by the seller, per line and per
> quantity, and the money is `RefundLinesAction` **unchanged** — same kuruş, same
> proportional commission, same restock, same `refund_debit` +
> `refund_commission_credit`. What C1 adds is a trigger, a gate and a word.
>
> **The gate is a shipment STATE, not a time window.** While the parcel is
> `pending` the seller may shed a line they cannot fill; once it is `shipped` the
> effort is spent and the buyer's route is the return (ADR-064). Payment reads that
> through a new Core port, `ShipmentQueryContract`, and imports no Shipping class.
> **A missing shipment REFUSES**, because reading the absence of a row as "not
> shipped yet" refunds a parcel that may already be with a carrier — the one
> mistake here nothing later can undo.
>
> **`CancelOrderLinesAction` is the mirror of `RequestReturnAction`**, deliberately
> the same shape: check who is asking and whether the moment is right, then hand
> the same action the same DTO. Ownership is verified behind the port even though
> the seller's panel already scoped its query — a panel's tenancy is a query
> somebody can get wrong. The quantity guard is `RefundableLines`, untouched:
> asking for three of two is a refusal, not a clamp.
>
> **`PaymentRefunded` gained a `cause`** (`return` | `cancellation`) and it is the
> only thing separating the two once the money has moved. A fully cancelled order
> becomes `cancelled` with a `cancelled` parcel; a fully returned one `refunded`
> with a `returned` parcel. A second event was the obvious alternative and is the
> wrong one — two listeners in Order would race to set different terminal states on
> one order, decided by registration order. It also carries the `reason`, so the
> sentence a seller owes the buyer reaches the order screen rather than sitting in
> an admin-only refund row.
>
> **The seller's lever lives on Order's own screen, driven through a Core COMMAND
> port** (`OrderCancellationContract`) — the platform's second, after Inventory's
> reservations. @see §12 and the ADR-065 amendment for why an event could not carry
> it and why the port also answers `cancellableQuantities()`.
>
> **What it broke on the way, recorded because it is the interesting part.** Making
> `OrderStatus: Paid → Cancelled` legal — needed so a cancellation can name its
> outcome honestly — armed `CancelOrderAction`, which releases a hold and zeroes a
> seller's declared stock, on a PAID order. It had been safe only because the
> transition did not exist. Both it and `OrderPolicy::cancel()` now refuse on
> `isCancellableWithoutRefund()`: a paid order is cancelled by refunding it, or not
> at all.
>
> No API surface. C1 is a seller-panel operation; C2 adds the buyer's request
> endpoints.

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
  *Complete 2026-08-04 — see the "As built (P5)" note in §8. The module's build order
  is finished; what remains is listed in §11.*

Two later phases belong to **Shipping's** work order (BUILD_SHIPPING.md) but were built
here, because what they needed was a delivery date this module could not produce for
itself:

- **S3 — Settlement windows + automatic payout.** `ShipmentDelivered` (class-string)
  opens the payout hold and the return window. *Complete 2026-08-05/06 — §8.*
- **S4 — Buyer return + line-level partial refund.** *Complete 2026-08-06 — §8. It
  closes two of §11's follow-ups and drops two unique indexes; read the note before
  changing anything about refund idempotency.*

## 11. Deliberately absent / follow-ups

- **Submerchant/marketplace settlement** — the licensing-clean model; a future ADR
  (§2). The ledger is shaped to make that migration additive.
- **e-fatura / commission invoicing** — commission produces a number; issuing the
  legal invoice is out of software scope.
- **Payout automation** (bank API) — v1 is manual/batch with a recorded reference.
- **Installments (taksit) economics** — PayTR supports taksit; v1 passes it through
  (buyer may choose taksit) but does not model the vade-farkı split. A follow-up.
- **Wallet / store credit, partial captures, subscriptions** — out of scope.
- **`payout_reversal_credit`, the sixth ledger type** — added by P4 because a rejected
  transfer's debit cannot be deleted from an append-only ledger and none of ADR-062's
  five types means "that payout did not happen". **Still awaiting the owner's
  ratification** of ADR-062.
- ~~**A customer-facing refund/cancel**~~ — **closed by S4 (2026-08-06).** It waited
  for a fulfilment state to judge it by; Shipping's delivery date and return window
  (ADR-064) supplied one. It did not attach at `PaymentPolicy::refund()` as P5
  predicted: ownership, delivery and a clock are questions about an ORDER and its
  parcel, not about a payment, so they live in `RequestReturnAction` and that ability
  still means "reverse a charge without any of them holding".
- ~~**Partial refund of a single order**~~ — **closed by S4 (2026-08-06),** at LINE +
  QUANTITY granularity. The ruling P5 said it needed: the frozen commission is SCALED
  by the refunded share, half-up, in integer arithmetic; the last unit of a line takes
  whatever rounding remains. @see §8's S4 note.
- **A return is not a request — it is the refund.** There is no approval step, no
  "iade talebi" queue and no state between asking and the money moving: the window IS
  the approval. If the platform ever wants a human to inspect goods before paying,
  that is a new aggregate and a new ADR, not a flag on `payment_refunds`.
- **Nothing checks that the goods actually came back.** A buyer inside the window is
  refunded and the stock is restocked on their word; the seller's recourse is the
  return window's length and, eventually, a dispute process nobody has specified. The
  honest statement of v1's risk, not an oversight.
- **`payment_refunds` and `seller_ledger_entries` lost their unique indexes** to make
  line-level refunds possible (§8). The remaining-quantity check in `RefundableLines`
  is the only thing standing between a double-click and a double refund. A
  `(payment_refund_id, order_line_uuid)` unique index would add back some of what was
  lost and is worth doing if refunds ever stop being rare.

## 12. What this requires of other modules (read-only, contract-level)

- **Order** — Core read of a checkout_group's orders + their line snapshots (amounts,
  KDV, seller), and the existing reservation-commit command port. No Order import.
  **S4 added three things to `OrderQueryContract`, all read-only and all Order's own
  data:** `id` and `commission_minor` on `orderLines()` (a return names a LINE, and
  reversing a commission proportionally means reading the figure that was frozen), and
  `checkoutGroupFor()` — the inverse of `ordersForCheckoutGroup()`. The last one
  replaced a derivation that worked: Payment could find an order's group by walking its
  own settled payments, and did, until it was noticed that this is a scan of every
  settled payment plus a query per payment on an endpoint a customer taps.
  **`reservationReferencesFor()` is now KEYED BY VARIANT UUID** for the same class of
  reason: a line-level restock needs one reference, and a caller that cannot look one up
  assembles `{order_uuid}:{variant_uuid}` itself — which is precisely the drift that
  method exists to prevent. A `foreach` over the values is unaffected, so P5's caller
  did not change.
- **Inventory** — `restock()` on the command port became `restock($reference,
  ?$quantity)` in S4 (amends ADR-049; P5 added the verb). `null` means all of it, so no
  existing caller changed.
- **Shipping** — a Core READ of the order's shipment state (`ShipmentQueryContract`,
  ADR-065/C1), which is the whole cancellation gate. No Shipping import; Shipping learns a
  cancellation happened from `PaymentRefunded`'s `cause` and closes its own parcel.
- **Order** — and here the direction reverses for the first time: `OrderCancellationContract`
  is a Core **command** port Payment IMPLEMENTS and Order's seller panel CALLS. Every other
  entry on this list is Payment asking; this is Payment answering. It exists because the
  seller's cancel button belongs on the order screen and the refund belongs here, and an
  event cannot tell somebody *now* that they asked for three of two.
- **Catalog** — the line already carries product/brand/category ids for commission
  resolution (snapshotted at order time); Payment reads them off the order, not the
  live catalogue.
- **Organization/Store** — seller org uuid for the ledger; read by id only.

None of these is a new cross-module dependency: all go through Core, exactly as
Offer/Inventory/Order do.
