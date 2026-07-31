# Order Module Specification

**Status: COMPLETE (v1.0), NOT frozen — 2026-07-31.** Built in phases P0–P7; what
shipped, what is deliberately absent, the deviations and the follow-ups are in **§12**.
Deliberately **not frozen**: **Payment** is the next sprint and moves the stock COMMIT
to payment-success (ADR-054), which is a change to this module's placement path. The §0
decisions and the §10 rulings are ratified (reservation-expiry default: **30 minutes**).
**ADR-052 … ADR-056 are recorded** in
[docs/Architecture_Decision_Record.md](../Architecture_Decision_Record.md), with their
mirror in the amendment log at the end of [docs/001_Architecture.md](../001_Architecture.md)
(the way Store landed ADR-032…036, Catalog ADR-037…041, Offer ADR-042…046, Inventory
ADR-048…051), the Catalog `tax_rates` + `Product.tax_rate_id` addition is recorded in
Catalog.md §2.4, and CLAUDE.md narrows the module prohibition to Payment. This document
states each decision **and its cost**, per project culture. Build order:
[BUILD_ORDER.md](../../BUILD_ORDER.md).

Order is the next major sprint after **Inventory** (complete, not frozen). It is the
**buyer's purchase pipeline** and the platform's largest module: a multi-seller cart, a
checkout that splits into one order per seller, the **first real caller of Inventory's
reservation contract**, and price/tax snapshotting. **Payment (money capture), Shipping,
commission/payout, and the customer storefront UI are explicitly out of scope** and land
in later, separately-reviewed sprints. This sprint carries an order up to — but not
through — payment: it stops at **awaiting payment**.

---

# 0. Scope and the decisions

## 0.1 What Order IS

The customer's path from basket to placed order. A logged-in **Customer** fills one
**cart** with items from many sellers, checks out, and the checkout **splits into one
`Order` per seller** (per store) under a shared checkout group. Order reserves stock at
checkout and commits it on placement (through Inventory's reservation contract),
**snapshots** the offer price / title / tax rate onto immutable order lines, computes the
KDV breakdown, and leaves each order **awaiting payment**.

It owns: the **cart**, the customer **address book**, the **order** aggregate (+ lines +
address snapshots), the order lifecycle up to awaiting-payment, and the `OrderQueryContract`
that Payment/Shipping will later read.

## 0.2 What Order is NOT (and where those live)

| Concern | Owner |
|---|---|
| Price, the priced listing, the buy box | **Offer** (shipped) |
| On-hand / reservations / availability | **Inventory** (shipped) |
| Product / variant / **tax bracket** | **Catalog** (shipped; tax addition here, §0.7) |
| Money capture, refunds, **commission, payout, settlement** | **Payment / Finance** (later) |
| Shipping, carriers, tracking, delivery | **Shipping** (later) |
| The customer storefront UI | **Next.js storefront** (separate app, later) |

**Order does not move money and does not compute commission.** It records what the customer
agreed to pay (items + KDV). Capturing it, taking the platform's cut, and paying the seller
are Payment/Finance. An order sits at **awaiting payment**; nothing charges a card this
sprint.

## 0.3 ADR-052 — The cart is multi-seller; checkout splits into one Order per seller

One customer, **one cart**, items from any number of sellers. At checkout the cart is
partitioned by selling org and **each partition becomes its own `Order`** (its own number,
status, totals, and seller), all tied together by a **`checkout_group_uuid`** the customer
sees as a single purchase. Each seller sees and manages only their own order.

**Cost.** There is no single "order total the customer paid" row — a purchase is N orders
that must be grouped for the customer and summed across for a receipt; and a future Payment
must reconcile one charge against many orders. We accept it because per-seller orders are
the marketplace model (each seller fulfils, ships, and is paid independently) — a single
cross-seller order would entangle fulfilment and payout across parties who share nothing.

## 0.4 ADR-053 — Order lines are immutable snapshots

An `OrderLine` **snapshots** the offer's unit price, the product title, the variant label
and the **tax rate** at placement. The catalog, the offer and its price may all change
afterward; the order records **what was bought, at what price, at what tax** — forever.

**Cost.** Order duplicates data that lives authoritatively in Catalog/Offer, and a
corrected typo in a product title will not reflect on past orders. We accept it because an
order is a financial/legal record: it must not mutate when an upstream price or name
changes, or every historical total and invoice becomes unreproducible.

## 0.5 ADR-054 — Checkout reserves stock; placement commits (two-step, via Inventory)

Order is the **first real caller of `InventoryReservationContract`** (ADR-049). Checkout
**reserves** each line's stock (a hold, keyed on the order uuid); placing the order
**commits** it (the units leave); a cancelled or expired checkout **releases** it. Until
**Payment** exists, placement commits directly; when Payment ships, the commit moves to
"payment succeeded" and placement only holds — the reservation window Inventory was built
for.

**Cost.** Stock leaves on placement with no money taken yet (there is no Payment), so a
placed-but-never-paid order would consume stock until cancelled/expired; we mitigate with
reservation expiry and manual/auto cancel, and accept it because the alternative — no
commit until a Payment module that does not exist — leaves every order's stock in limbo.
The two-step shape is exactly what lets Payment slot in without reshaping the flow.

## 0.6 ADR-055 — Order computes tax from the product's bracket, never commission

Each order line carries the **KDV** extracted from its (tax-included, ADR-042) price using
the **product's tax rate** (§0.7). Order produces the tax breakdown for the eventual
invoice. It does **not** compute commission or payout — those are Payment/Finance, applied
to the order later.

**Cost.** Tax logic (rounding, the inclusive-KDV extraction) lives in Order before an
invoicing module exists to consume it, and we commit to "price is KDV-included" (Offer's
decision) platform-wide. Accepted: a total with no tax breakdown is not a real order line,
and commission has no source of truth yet (ADR-042 §0.2).

## 0.7 ADR-056 — Customer address book; separate shipping & billing, both snapshotted

Order owns a **`CustomerAddress`** book: a customer keeps **many** addresses (defaults for
shipping and billing). At checkout the customer picks a **shipping** address and a
**billing** address — which **may differ** — and the order **snapshots both** (§0.4's
immutability applies to addresses too). Authenticated customers only (owner decision); no
guest checkout in v1.

**The Catalog tax addition (not frozen, driven by this sprint):** a managed **`tax_rates`**
lookup (admin-configured KDV brackets — %1/%10/%20 — the lookup-table the docs always
intended) is added to Catalog, and the **Product gains a `tax_rate_id`** chosen at
authoring ("ürün aç") and moderated with the rest. A tax bracket is a **classification of
the product**, not a commercial term, so it does not breach ADR-037's "a product has no
price or stock." Order reads the rate via `CatalogQueryContract` (or the offer snapshot)
and freezes it onto the line.

**Cost.** The address book and the tax lookup widen this sprint beyond the order aggregate
itself, and the Catalog (frozen? no) gains a field and a table for Order's sake. Accepted:
real invoices need a real billing address and a real KDV rate — faking either would make
the order legally useless.

## 0.8 Scope of THIS sprint

**In scope:** cart (multi-seller) + items; the customer address book; checkout (validate,
reserve, split, snapshot price+tax+addresses); place (commit, awaiting payment); cancel/
expire (release); the order aggregate + immutable lines; KDV computation; the customer API
(cart/addresses/checkout/orders — for the future storefront); seller & admin Filament order
surfaces; the `OrderQueryContract`; events; and the Catalog `tax_rates` + `Product.tax_rate_id`
addition (§0.7).

**Out of scope:** payment capture/refunds, commission/payout, shipping, the customer
storefront UI, guest checkout, discount/coupon engines, returns/RMA.

**Cost of phasing.** Orders reach "awaiting payment" and stop; nothing charges or ships, and
the customer side is API-only until the storefront app exists. Accepted — the same
discipline that phased Offer → Inventory → Order.

---

# 1. Purpose

## 1.1 Responsibilities
- Own the customer cart (multi-seller) and the customer address book.
- Check out: validate, reserve stock, split into one order per seller, snapshot price/tax/
  addresses, place (commit), cancel/expire (release).
- Own the order aggregate + immutable lines + address snapshots + lifecycle to awaiting-payment.
- Compute the KDV breakdown from the product's tax bracket.
- Publish events and expose `OrderQueryContract` for Payment/Shipping.

## 1.2 Non-responsibilities
Price/buy box (Offer); stock/reservation *mechanism* (Inventory — Order calls it);
product/tax-bracket authority (Catalog); money/commission/payout (Payment); shipping
(Shipping); the storefront UI (Next.js). See §0.2.

## 1.3 Module boundaries
Standard modular monolith (ADR-002). **Order imports no module** — it reads Offer/Inventory/
Catalog/Store/Organization through Core contracts and **calls** `InventoryReservationContract`
(the first module to drive a Core **command** contract). `LayeringTest` stays green.

## 1.4 Relationships
- **Offer** — `OfferQueryContract`: validate an offer is active, read its live price for the
  cart, snapshot it at checkout. By uuid.
- **Inventory** — `InventoryReservationContract` (reserve/release/commit) + `InventoryQueryContract`
  (pre-check). The reservation reference is the order uuid.
- **Catalog** — `CatalogQueryContract`: product/variant titles + the product's tax rate.
  Plus the §0.7 addition (tax_rates + Product.tax_rate_id) built here.
- **Store / Organization** — `StoreQueryContract` (store active), `OrganizationAuthorizationContract`
  (seller order-panel tenancy). By uuid.
- **Customer (Identity)** — the order's owner is a `Customer` (authenticated). Identity is
  frozen and untouched; Order references the customer by id/uuid.
- **Later** — Payment (reads `OrderQueryContract`, adds the money gate), Shipping, Finance.

---

# 2. Domain Model

## 2.1 `Cart` + `CartItem`
`Cart`: one active per customer — `customer_id`/`uuid`, timestamps. `CartItem`: `offer_uuid`
+ denormalized `variant_uuid` / `product_uuid` / `selling_org_uuid` / `store_uuid` (for
grouping), `quantity`. Price is **not** stored on the cart — read live from Offer for
display; snapshotted only at checkout.

## 2.2 `CustomerAddress` (address book)
`customer_id`/`uuid`, `label`, `recipient_name`, `phone`, `line1`, `line2`, `district`,
`city`, `postal_code`, `country_id`, `is_default_shipping`, `is_default_billing`. Many per
customer; customer-scoped CRUD.

## 2.3 `Order` (aggregate root, one per seller per checkout)
`id` · `uuid` · `order_number` · `checkout_group_uuid` (groups a purchase) · `customer_id`/
`uuid` · `selling_org_uuid` · `store_uuid` · `status` (`OrderStatus`) · `currency_id` ·
`items_total_minor` · `tax_total_minor` · `grand_total_minor` (integer minor units) ·
`shipping_address` + `billing_address` (**snapshots**, §0.4) · `placed_at` · timestamps.

## 2.4 `OrderLine` (immutable snapshot)
`order_id` · `offer_uuid` · `variant_uuid` · `product_uuid` · `product_title` · `variant_label`
· `unit_price_minor` (KDV-included) · `tax_rate` (DECIMAL, ADR-005) · `quantity` ·
`line_tax_minor` · `line_total_minor`. Frozen at placement.

## 2.5 Enums (module-owned, no `Enum` suffix — ADR-007)
`OrderStatus` — `Pending` (reserved during checkout), `AwaitingPayment` (placed, committed),
`Cancelled`. Forward-looking states (`Paid`, `Preparing`, `Shipped`, `Delivered`,
`Completed`, `Returned`) arrive with Payment/Shipping/Returns; not built now.

---

# 3. Business Rules

## 3.1 Checkout & split
Validate every cart line (offer active, store active, `InventoryQueryContract::isAvailable`).
Partition by `selling_org_uuid`; each partition → one `Order` (`Pending`) under a shared
`checkout_group_uuid`. Snapshot unit price (from Offer), tax rate (from the product),
product/variant labels, and the chosen shipping + billing addresses. **Reserve** each line's
stock via `InventoryReservationContract::reserve(org, variant, qty, orderUuid)`. Any line
that cannot reserve fails the whole checkout (all-or-nothing) and releases what was held.

## 3.2 Placement
Placing a checkout group **commits** every reservation (`commit(orderUuid)`) and moves each
order to `AwaitingPayment`. (When Payment ships, commit moves to payment-success; placement
then only holds.)

## 3.3 Cancel / expiry
A `Pending` or `AwaitingPayment` order cancelled (by the customer before fulfilment, the
seller, an admin, or a reservation-expiry sweep) **releases** its reservation and moves to
`Cancelled`. Idempotent — a double cancel does not double-release (Inventory's primitives
are idempotent on the reference).

## 3.4 Tax (KDV)
Prices are **KDV-included** (ADR-042). Per line the included KDV is extracted with the
product's `tax_rate`: `line_tax = line_total − round(line_total / (1 + tax_rate))`.
`items_total` = Σ line_total (tax already inside); `tax_total` = Σ line_tax; `grand_total` =
items_total (no shipping this sprint). Money is integer minor units; APIs format decimal
strings.

## 3.5 Money & integrity
All amounts integer minor units (#6); `tax_rate` is DECIMAL (ADR-005). An order's totals are
the sum of its lines and are written once at placement. Reservation math is Inventory's
(row-locked); Order never touches stock rows directly.

---

# 4. Surfaces
- **Customer API** (for the future storefront, no Filament): cart CRUD; address-book CRUD;
  `POST /checkout` (pick shipping+billing, reserve, split); `POST /checkout/{group}/place`
  (commit); `GET /orders` / `GET /orders/{uuid}`; cancel. Customer-scoped.
- **Seller Filament — "Siparişlerim":** the seller's orders for their store(s) — lines,
  totals, status; confirm/cancel where allowed. Tenancy-scoped via `organizationIdsForUser()`.
- **Admin Filament — order oversight:** view all, cancel; gated on `order.*` perms.
- **Catalog admin (§0.7):** a `tax_rates` resource (admin/Category-Manager manages brackets)
  and a tax-bracket field on the product authoring form.

---

# 5. Contracts (Core)
- **`OrderQueryContract`** (new — Order implements): `orderExists`, `orderStatus`,
  `ordersForCheckoutGroup`, `orderTotals` — the read port Payment/Shipping use without
  importing Order.
- Order **consumes** `OfferQueryContract`, `InventoryQueryContract`,
  `InventoryReservationContract` (command), `CatalogQueryContract`, `StoreQueryContract`,
  `OrganizationAuthorizationContract`. The Catalog `tax_rates`/`tax_rate_id` addition may
  extend `CatalogQueryContract` with a `taxRateForProduct` read.

# 6. Events (module-owned, past tense)
`CartCheckedOut`, `OrderPlaced`, `OrderCancelled`. Audit records them; Notification tells
customer + seller; Payment (later) subscribes to `OrderPlaced` to open a charge; Inventory
is driven by the **contract**, not these events.

# 7. Policies — roles & capabilities
- **Customer** owns their own cart, addresses and orders (BasePolicy `owns()` overridden).
- **Seller** sees/manages only their store's orders (org capability + `organizationIdsForUser`).
- **Admin** oversight via `order.*` perms (`view_any`, `view`, `cancel`) through
  `PermissionRegistry` + `RolePermissionSeeder`.

# 8. Non-negotiables recap
`declare(strict_types=1)`; **money = integer minor units**, APIs decimal strings (#6);
`tax_rate` DECIMAL (ADR-005); UUID public / internal id never leaves (#7); Domain imports no
Eloquent/Request/DB facade, no `cache()/request()/encrypt()` (ADR-019); no `dd/dump/die`;
roles by name; policies check permissions; DTOs `...DTO` in `Domain/DTOs/` (ADR-021); side
effects in `after()`.

# 9. Proposed Application actions
`AddCartItemAction` / `UpdateCartItemAction` / `RemoveCartItemAction`; address-book
`Create/Update/Delete/SetDefault`; `CheckoutAction` (validate + reserve + split + snapshot);
`PlaceOrderAction` (commit); `CancelOrderAction` (release); an `ExpireReservationsJob`
(release stale holds). Each: one transaction, event in `after()`; the reserve/commit/release
go through the Inventory contract.

# 10. Open rulings to confirm at approval
1. **Multi-seller cart, one order per seller** under a checkout group (owner-confirmed).
2. **Two-step reserve→commit**; placement commits until Payment (owner-confirmed).
3. **KDV from the product's bracket** via a managed `tax_rates` lookup added to Catalog;
   prices KDV-included; commission excluded (owner-confirmed).
4. **Address book** with separate, snapshotted shipping & billing; authenticated only
   (owner-confirmed).
5. **Reservation expiry window** — a checkout hold expires after N minutes (config), then a
   sweep releases it. Confirm the default (e.g. 30 min).

# 11. Phasing after this sprint
Order (this) → **Payment/Finance** (money capture, the payment gate before commit,
commission, payout, refunds; reads `OrderQueryContract`) → **Shipping** (fulfilment,
carriers, tracking) → returns/RMA. Each is its own spec + architecture review.

## Ratification checklist
- [x] Record ADR-052…056 in the ADR record + amendment log (2026-07-30).
- [x] Confirm the §10 rulings — reservation-expiry default **30 minutes** (owner-confirmed).
- [x] Author the Catalog `tax_rates` + `Product.tax_rate_id` change owner-side (Catalog.md
      §2.4) so the build executor never manufactures the architecture change.
- [x] Narrow the CLAUDE.md module prohibition to Payment.
- [x] Build in phases, one commit per phase, suite green, human pushes.

---

# 12. What this sprint shipped

## 12.1 Delivered

| Area | Where |
|---|---|
| `tax_rates` lookup + `Product.tax_rate_id` + `taxRateForProduct` (§0.7, ADR-056) | Catalog — `Domain/Models/TaxRate`, `Presentation/Filament/Resources/TaxRateResource` |
| `Cart` + `CartItem` — multi-seller, **no prices** (§2.1) | `Domain/Models/` |
| `CustomerAddress` — the book, with separate defaults (§2.2) | `Domain/Models/CustomerAddress` |
| `Order` + `OrderLine` — immutable snapshots, address JSON (§2.3/2.4) | `Domain/Models/` |
| `OrderStatus` — three cases, no `Enum` suffix (§2.5) | `Domain/Enums/OrderStatus` |
| Schema: five tables, **no cross-module FK**, pgsql partial default-address indexes | `database/Modules/Order/migrations/` |
| Cart + address-book actions (§9) | `Application/Actions/` |
| `CheckoutAction` — validate, split, snapshot, tax, **reserve** (§3.1) | `Application/Actions/CheckoutAction` |
| `PlaceOrderAction` — **commit** the group (§3.2) | `Application/Actions/PlaceOrderAction` |
| `CancelOrderAction` — **release**, idempotent, attributed (§3.3) | `Application/Actions/CancelOrderAction` |
| `ExpireReservationsJob` — the 30-minute sweep (§3.3) | `Application/Jobs/` |
| KDV extraction, float-free (§3.4) | `Domain/Support/IncludedTax` |
| Live cart pricing (§2.1) | `Application/Services/CartPricingService` |
| Customer API — cart, addresses, checkout, place, orders, cancel (§4) | `Presentation/Controllers/Api/`, `routes/api.php` |
| Seller "Siparişlerim" + admin oversight (§4) | `Presentation/Filament/` |
| `OrderPolicy` — three audiences (§7) | `Presentation/Policies/OrderPolicy` |
| Three events (§6) | `Domain/Events/` |
| Core read port (§5) | `App\Core\Domain\Contracts\OrderQueryContract` |

Order imports **no** module and nothing imports Order — asserted in `LayeringTest`
in both directions. It is the first module to DRIVE a Core **command** contract
(`InventoryReservationContract`), and Inventory's own permitted-importer list needed
no change to accommodate it.

## 12.2 Deliberately absent

**No payment, and that is where the sprint stops.** An order reaches
`AwaitingPayment` and waits (§0.8). **No commission, no payout, no settlement**
(ADR-055) — Order records what the customer agreed to pay; taking the platform's cut
is Payment/Finance. **No shipping, no carriers, no tracking.** **No customer UI** —
the API ships and waits for the Next.js storefront. **No guest checkout** (ADR-056):
authenticated customers only, so a basket belongs to an account from the first item
and is never migrated from a session at login. **No discounts, coupons, returns or
RMA.**

**No "confirm" on the seller surface**, though §4 mentions it: an order is
`AwaitingPayment` and the next real transition belongs to Payment, so a button that
only moved a status nothing reads would be a lie about what the platform can do.

**No `Paid`, `Shipped` or `Delivered` status**, for the same reason: every one of them
belongs to a module that does not exist, and an enum case nothing can set reads as a
capability the platform has.

## 12.3 Deviations from this document, and why

1. **The reservation reference is PER LINE, not per order.** §3.1 writes
   `reserve(org, variant, qty, orderUuid)` and §3.2 `commit(orderUuid)`. An Inventory
   reservation is one row on a UNIQUE reference and reserving is idempotent on it, so
   an order with two lines sharing one reference would silently leave the second
   unheld — the first line held, the second not, and nothing anywhere saying so. The
   reference is `{order_uuid}:{variant_uuid}`, derivable at commit and release without
   storing an Inventory identifier, and unique because a seller may hold at most one
   active offer per variant (ADR-042 §3.2). The decision is unchanged; only its key is.

2. **Cancelling a PLACED order does not restock.** §3.3 says a `Pending` or
   `AwaitingPayment` order "releases its reservation" — and for a committed one,
   Inventory's `release()` is a documented no-op, so the status moves and the units do
   not come back. Inventory has no un-commit primitive and should not grow one by side
   effect: reversing a sale is a different business event from abandoning a hold, and
   conflating them makes "why did my stock go up" unanswerable in the ledger.
   Implemented as written, documented in `CancelOrderAction`, and raised as
   follow-up #1.

3. **A missing hold does not block a cancellation.** Inventory's `release()` throws
   when nothing was ever reserved under a reference — correct for Inventory, wrong
   from here: an order can legitimately reach cancellation with no live hold (an
   import, a restore, a hold already returned), and refusing would leave a customer
   with an order nobody can stop. Each line is released on its own and a failure is
   logged. `PlaceOrderAction` deliberately does NOT swallow the same failure —
   releasing twice is harmless, committing a hold that does not exist sells stock
   nobody reserved.

4. **`OrderQueryContract` has no command half**, unlike Inventory's pair. Nothing
   outside Order may place, cancel or re-price an order: stock is a resource other
   contexts legitimately borrow, an order's state machine is not something anyone else
   may drive. When Payment needs the status to move it raises its own event and Order
   reacts.

## 12.4 Changes this sprint required of other modules

Recorded in the `001_Architecture.md` amendment log.

| Module | Change | Why it could not be avoided |
|---|---|---|
| Catalog (not frozen) | `tax_rates` lookup + `Product.tax_rate_id` + `CatalogQueryContract::taxRateForProduct()` | §0.7/ADR-056's sanctioned change. A line cannot be priced for tax without a rate, and a bracket is a classification of the goods rather than a commercial term — so it belongs to the Catalog and not to the Offer |
| Offer (not frozen) | `OfferQueryContract::activeOfferByUuid()` | §1.4 asks for it in those words. Every other method answers a LIST question, because until Order existed every caller arrived holding a product or a store; a cart line arrives holding an offer. It reuses the buy box's own eligibility, so "can this go in a basket" and "is this what a product page would feature" cannot drift |
| Core (Offer→Core move) | `MoneyString` promoted from `Offer\Presentation\Support` to `Core\Presentation\Support` | Its own docblock named this condition: the second module to need it. Order renders money and may not import Offer, so the choice was Core or a copy — and a second money formatter ends with two endpoints disagreeing about a kuruş |

## 12.5 Follow-ups

1. **Cancelling a placed order leaves its stock committed** (§12.3 #2). The fix is an
   Inventory decision, not an Order one: a `restock`/reversal primitive with its own
   movement type, so the ledger can say a sale was reversed rather than implying units
   appeared. Needs its own ruling before Payment ships, because a refund will want it.

2. **The seller has no "confirm"** (§12.2). It becomes real when Payment does — a
   seller accepting a PAID order is a meaningful transition; accepting an unpaid one is
   not.

3. **`ExpireReservationsJob` is not scheduled.** The job exists, is idempotent and is
   tested; nothing calls it on a timer yet. One line in the scheduler, deliberately
   left to whoever configures the deploy's queue topology.

4. **`OrderQueryContract` has no caller** — by design (§5), exactly as Inventory's
   contracts shipped a sprint early. Payment is the first.

5. **Order totals are single-currency.** Every offer is priced in the platform default
   (Offer §13.1), so `CheckoutAction` reads that one currency. Multi-currency is one
   place in that action plus a decision about which currency a mixed basket settles in.

6. **No `orders` search index and no seller-facing reporting.** "What did I sell last
   month" is answerable from `order_lines` today only by SQL. Deliberate: reporting
   shapes follow from Payment's data, and building them now would guess.
