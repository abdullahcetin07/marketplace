# Work order — build the Order module (cart → checkout → per-seller orders)

**Disposable. `git rm` when done.** For the server-side session (has `vendor/`, DB, tests).
Owner-approved 2026-07-30. The authoritative design is
**[docs/modules/Order.md](docs/modules/Order.md)** — read it fully before writing code.
ADR-052…056 are **already recorded owner-side** (ADR record + 001 amendment log), the
Catalog `tax_rates` + `Product.tax_rate_id` addition is noted in **Catalog.md §2.4**,
Order.md is APPROVED, and CLAUDE.md's prohibition is narrowed to Payment — do **not**
re-author them; if any are missing after `git pull`, STOP and report (ADR-018). Build
**one commit per phase**, keep the suite green (`php artisan test`), human pushes.

## What Order is (one paragraph)
The buyer's pipeline. A logged-in **Customer** fills **one multi-seller cart**, checks out,
and the checkout **splits into one `Order` per seller** under a `checkout_group_uuid`. It
**reserves** stock at checkout and **commits** on placement via Inventory's reservation
contract (Order is its **first real caller**), **snapshots** offer price / title / **KDV
rate** / addresses onto immutable lines, computes the KDV breakdown, and stops at
**AwaitingPayment**. **No payment, no shipping, no commission, no customer UI, no guest
checkout.** See Order.md §0.

## Hard rules (build fails otherwise)
- **Imports nothing.** No `use App\Modules\{Offer|Inventory|Catalog|Store|Organization}\...`.
  Read via Core contracts; **call** `InventoryReservationContract` (reserve/release/commit) —
  Order is the first module to drive a Core command contract. `LayeringTest` stays green.
- **Money = integer minor units** (#6), APIs decimal strings; **`tax_rate` DECIMAL** (ADR-005).
  Prices are **KDV-included** (ADR-042) — extract KDV, don't add it: `line_tax = line_total −
  round(line_total / (1 + tax_rate))`.
- **Order lines + address snapshots are immutable** (ADR-053) — frozen at placement.
- **UUID public / internal id never leaves**; `declare(strict_types=1)`; Domain imports no
  Eloquent/Request/DB facade, no `cache()/request()/encrypt()`; no `dd/dump`; DTOs `...DTO`
  in `Domain/DTOs/`; side effects in `after()`; roles by name; policies check permissions.
- **Authenticated customers only** — cart/addresses/orders belong to a `Customer`; no guest.
- **All-or-nothing checkout**: if any line can't reserve, the whole checkout fails and every
  hold taken so far is released.

## Build phases (one commit each)

**P0 — Catalog tax addition (Catalog NOT frozen; owner-approved, ADR-056 / Catalog.md §2.4).**
Add a managed **`tax_rates`** lookup (id, uuid, name, `rate` DECIMAL, `is_active`) with an
admin/Category-Manager Filament resource + a seeder (TR brackets %1/%10/%20/%0). Add
**`Product.tax_rate_id`** (→ tax_rates), a **required** select on the "ürün aç" authoring
form, carried through moderation. Extend `CatalogQueryContract` with
`taxRateForProduct(string $productUuid): ?string` (or `...ForVariant`). Existing products
need a data backfill/default so they stay valid. Green.

**P1 — scaffold.** `app/Modules/Order/{Domain,Application,Infrastructure,Presentation}`,
provider, `config/order.php` (reservation-expiry minutes = **30** default), README, modules
index. Green.

**P2 — domain.** `Cart`+`CartItem`, `CustomerAddress`, `Order`, `OrderLine` (§2); enums
`OrderStatus` (`Pending`/`AwaitingPayment`/`Cancelled`, no `Enum` suffix); DTOs; events
(`CartCheckedOut`, `OrderPlaced`, `OrderCancelled`); the **Core** `OrderQueryContract`
interface (§5). Address snapshots as embedded columns/JSON on the order. Green.

**P3 — infra.** Migrations (carts, cart_items, customer_addresses, orders, order_lines;
indexes for customer/seller/checkout-group lookups); repositories (`$with` eager loads,
strict mode); factories; `OrderQuery` implementation. Order number + checkout-group uuid
generators. Green.

**P4 — cart + address book.** Actions: `AddCartItemAction` / `UpdateCartItemAction` /
`RemoveCartItemAction` (validate the offer via `OfferQueryContract`, one active cart per
customer); address-book `Create/Update/Delete/SetDefault`. Customer-scoped. Prices read
**live** from Offer for display, not stored on the cart. Green.

**P5 — checkout + placement + cancel (the core, drives Inventory).** `CheckoutAction`:
validate every line (offer active, store active, `InventoryQueryContract::isAvailable`),
**partition by selling org**, create one `Order` (`Pending`) per partition under a shared
`checkout_group_uuid`, **snapshot** unit price (Offer) + tax rate (Catalog) + labels +
chosen **shipping & billing** addresses, compute KDV (§3.4), and **reserve** each line
(`InventoryReservationContract::reserve(org, variant, qty, orderUuid)`) — all-or-nothing.
`PlaceOrderAction`: **commit** every reservation → `AwaitingPayment`. `CancelOrderAction`:
**release** → `Cancelled` (idempotent). `ExpireReservationsJob`: release holds older than the
config window (30 min). Each action one transaction, event in `after()`. Green.

**P6 — presentation.** **Customer API** (for the future storefront): cart CRUD, address-book
CRUD, `POST /checkout` (shipping+billing ids), `POST /checkout/{group}/place`, `GET /orders`,
`GET /orders/{uuid}`, cancel — customer-scoped, money as decimal strings. **Seller Filament
"Siparişlerim"**: the seller's store orders (lines, totals, status; confirm/cancel),
tenancy-scoped via `organizationIdsForUser()`. **Admin Filament** order oversight, gated on
`order.*` perms (`PermissionRegistry` + `RolePermissionSeeder`). `OrderPolicy` (customer owns
own; seller own-store; admin perms). Green.

**P7 — hardening.** Tests + docs + sweep:
- Order imports no module (`LayeringTest`); Order **calls** the Inventory reservation
  contract (assert reserve→commit / reserve→release paths).
- Multi-seller cart splits into one order per seller under one checkout group.
- Snapshots are immutable: changing the offer price / product title after placement does not
  change a placed line.
- KDV extraction is correct (inclusive), totals = Σ lines; money renders as decimal strings.
- All-or-nothing checkout releases holds on failure; cancel releases; expiry sweep releases;
  double cancel does not double-release.
- Customer isolation (a customer sees only their cart/addresses/orders); seller sees only
  their store's orders; separate shipping vs billing snapshot correctly.
- Add an Order.md §12-style "delivered / deliberately absent / follow-ups" section.

## Notes / choices already made (don't re-litigate — report if you must deviate)
- **Reservation expiry = 30 min** (config).
- **Placement commits** this sprint (no Payment); Payment later moves commit to payment-success.
- **KDV from the product's bracket**, prices KDV-included, **no commission**.
- **Authenticated customers only**; **address book** with separate shipping/billing.
- Migrations + the tax_rates seeder + RolePermissionSeeder need running on the server after
  merge (`php artisan migrate --force && php artisan db:seed ...`).

## Finish
`git rm BUILD_ORDER.md`, commit. Report the `php artisan test` count, the ADR entries
confirmed present, and a short live/Livewire note (a customer builds a 2-seller cart →
checkout → 2 orders, stock reserved then committed, KDV shown; a seller sees only their
order). If anything conflicts with the docs chain, STOP and report.
