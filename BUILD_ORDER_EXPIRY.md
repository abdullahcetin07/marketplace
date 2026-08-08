# BUILD — Order payment-window expiry (5-min reservation release)

**ADR:** ADR-072 (amends ADR-052/054/057). **Owner-approved design.**

**The problem it fixes (live, money-critical):** a placed order sits in
`AwaitingPayment` holding a stock reservation; if the customer never pays, the hold
is never released, so a seller's `available = on_hand − reserved` falls to 0 and
their offer drops off the buy box **even though the offer still declares stock**.
This is the exact cause of the "Turuncukasa satıyor ama görünmüyor" bug — and this
sweep **self-heals it**: the first run releases every abandoned `AwaitingPayment`
reservation past the window.

**What we build:** an `AwaitingPayment` order expires after
`settings('order.payment_window_minutes')` (default **5**) → new `OrderStatus::Expired`
+ **release its reservation**; expired orders are **hidden from `GET /orders`**; a
**late payment success re-reserves-or-refunds** (never oversell, never keep a paid
customer empty-handed).

Build the phases **in order**; each ends with `make check` green + commit + push. If
anything contradicts the spec or an existing decision, **STOP and report** (ADR-018).

---

## Non-negotiables (restated)

- **`declare(strict_types=1)`**, no `dd/dump/die`. **Money = integer kuruş.**
- **Order imports NO module** — Payment/Inventory reached via Core contracts + class-string
  events only (`LayeringTest`).
- **Actions own one transaction** (`BaseAction`); side effects in `after()` (post-commit).
- **The scheduler is money-critical** — the sweep runs `->everyMinute()->onOneServer()`.
- **`make check` green before any phase is "done".**

---

## Verified facts (don't re-derive)

- **`OrderStatus`** (`app/Modules/Order/Domain/Enums/OrderStatus.php`): cases `Pending`,
  `AwaitingPayment`, `Paid`, `Delivered`, `Refunded`, `Cancelled` — **no `Expired` yet**.
  `transitions()`: `AwaitingPayment → [Paid, Cancelled]`. `color()` and `label()` are
  **exhaustive `match` with no default** — adding a case forces an arm in both (+ a `label`
  lang key). Helpers: `holdsReservation()` (=Pending||AwaitingPayment), `isTerminal()`
  (=Cancelled||Refunded), `isCancellableWithoutRefund()`, `canTransitionTo()`.
- **Reservation port** `App\Core\Domain\Contracts\InventoryReservationContract`:
  `reserve($sellingOrgUuid,$variantUuid,int $qty,string $reference): bool`,
  `release(string $reference): void` (idempotent; no-op if already released/committed),
  `commit(string $reference): void`. Reference = `Order::reservationReferenceFor($variantUuid)`
  = `{order_uuid}:{variant_uuid}` (NOT a uuid).
- **Release pattern** (mirror): `CancelOrderAction::releaseHolds()` loops `$order->lines`,
  `release($order->reservationReferenceFor($line->variant_uuid))` **in try/catch** (a missing
  hold is logged, never fatal). Or use `OrderQueryContract::reservationReferencesFor($orderUuid)`.
- **Payment success path** — TWO parts across the boundary:
  - `app/Modules/Payment/Application/Actions/SettlePaymentCallbackAction.php` `settle()`:
    verifies hash, loads `Payment` by uuid (`merchant_oid`), gates on `awaitsSettlement()`
    (idempotent), gets `ordersForCheckoutGroup(...)`, **`commit($reference)` per line**, sets
    `Payment=Paid`, dispatches `PaymentSucceeded` in `after()`. **Holds the Inventory port.**
  - `app/Modules/Order/Application/Listeners/SettleOrdersOnPayment.php` `onSucceeded($event)`:
    loads orders by `$event->orderUuids`, `freezeCommission()`, `transition($order, Paid)` —
    guarded by `canTransitionTo()`, logs+returns if illegal. **Has NO Inventory port** — it
    cannot re-reserve.
- **PayTR callback:** `app/Modules/Payment/Presentation/Controllers/Api/PayTrCallbackController.php`
  → always returns `"OK"`. `Payment` keyed on `checkout_group_uuid`; `PaymentStatus` has its own
  `Pending/Paid/Failed/Expired/Refunded/PartiallyRefunded` (separate lifecycle from Order).
- **Scheduler** lives in **`routes/console.php`** (Laravel 11; no `Console/Kernel.php`):
  `Schedule::job(new SweepTransitDeliveriesJob)->name('sweep-transit-deliveries')->hourly()->onOneServer();`
  and `CreateDuePayoutsJob ->dailyAt('07:00')->onOneServer();`. **`BaseJob` subclasses must call
  `parent::__construct()`.**
- **settings():** `settings('key', $fallback)` first, `config()` as floor (Shipping pattern:
  `SweepTransitDeliveriesJob::transitDays()`). `SettingGroup` enum
  (`app/Modules/Settings/Domain/Enums/SettingGroup.php`) has **no `Order`** — add one. Defaults
  registered in `database/Modules/Settings/Seeders/SettingsSeeder.php`
  (`$settings->register('shipping.transit_days', SettingGroup::Shipping, SettingType::Integer, 3, '...')`).
  `config/order.php` already has `reservation.expires_after_minutes` (30) — add
  `payment_window_minutes` (5) alongside as the fallback.
- **Customer order list:** `CustomerOrderController::index()` = `Order::query()->with(['lines','currency'])
  ->forCustomer(...)->orderByDesc('id')->paginate(...)`. `scopeForCustomer(Builder,int)` exists;
  sibling `scopeWithStatus`. `find()` (single order) uses the same scope.
- **Existing scaffolding:** `ExpireReservationsJob` (`app/Modules/Order/Application/Jobs/`) sweeps
  **`Pending` only** (`OrderRepository::expiredPending()` → `withStatus(Pending)` + backdated
  `created_at`, 30-min config), routes through `CancelOrderAction` (`BY_EXPIRY`) → **`Cancelled`**.
  It is **NOT on the schedule**. `['status','created_at']` index exists on `orders`. There is **no
  `expires_at` column** — expiry is computed from `created_at` + window.
- Tests: **Pest**, Feature/Modules get `RefreshDatabase`. `seedPlatform()`, `actingAsCustomer()`,
  `actingAsSeller()`, `actingAsAdmin()`.

---

## Phase E1 — `OrderStatus::Expired` + transitions

**Modify `OrderStatus.php`:**
- Add `case Expired = 'expired';`.
- `transitions()`: `AwaitingPayment → [Paid, Cancelled, Expired]`; **`Expired → [Paid]`** (the
  one recovery transition, ADR-072 late-payment path); `Expired` otherwise terminal-ish.
- `holdsReservation()`: unchanged (`Pending || AwaitingPayment`) — an `Expired` order has
  **released** its hold, so it holds none.
- `isTerminal()`: keep `Cancelled || Refunded` (Expired is NOT terminal — it can recover to Paid).
- `isCancellableWithoutRefund()`: unchanged (Pending/AwaitingPayment) — an expired order is not
  cancelled, it is already released.
- Add `color()` arm (`Expired => 'gray'`) and `label()` key (`order.status.expired` = "Süresi doldu").

**Tests:** `AwaitingPayment` can transition to `Expired`; `Expired` can transition to `Paid` and
nothing else; `Expired` holds no reservation.

**Steps:** edit → lang key → tests → `make check` → commit + push `feat(order): OrderStatus::Expired + transitions (ADR-072)`.

---

## Phase E2 — the tunable window (settings + config)

- **`config/order.php`**: add `'payment_window_minutes' => (int) env('ORDER_PAYMENT_WINDOW_MINUTES', 5),`.
- **`SettingGroup`**: add `case Order = 'order';` (with its label lang key).
- **`SettingsSeeder`**: register
  `$settings->register('order.payment_window_minutes', SettingGroup::Order, SettingType::Integer, 5, 'Ödeme penceresi (dk): ödenmeyen sipariş bu süre sonunda düşer.');`
- A helper (on the sweep action/job): `paymentWindowMinutes(): int` =
  `max(1, (int) settings('order.payment_window_minutes', (int) config('order.payment_window_minutes', 5)))`
  — settings first, config floor (Shipping pattern; settings never breaks boot).

**Steps:** edit → seeder → `make check` (a settings test asserts the default) → commit + push
`feat(order): payment_window_minutes setting (ADR-072)`.

---

## Phase E3 — the sweep: expire `AwaitingPayment` + release holds

**`OrderRepository`**: add `awaitingPaymentExpired(int $windowMinutes, int $limit = 100): Collection`
— `withStatus(AwaitingPayment)->where('placed_at', '<=', now()->subMinutes($windowMinutes))
->with('lines')->limit($limit)->get()`. (Uses `placed_at` — the moment it entered AwaitingPayment —
not `created_at`.)

> **If `placed_at` is null on placed orders**, use `updated_at` or add the column; verify against
> the `place` action which stamps AwaitingPayment. Report if `placed_at` isn't set at placement.

**`ExpireOrderAction`** (extends `BaseAction`, `handle(Order $order): void`):
1. Guard `$order->status === AwaitingPayment` (skip anything else — idempotent against races).
2. **Release every line's reservation**: loop `$order->lines`, `release($order->reservationReferenceFor($line->variant_uuid))` in try/catch (log, never fatal — mirror `CancelOrderAction::releaseHolds`).
3. `transition` to `Expired` (stamp `cancelled_at`? No — add nothing money-ish; just status + maybe an `expired_at`/note. Reuse existing timestamp columns; do NOT invent `cancelled_at` semantics).
4. `after()`: dispatch `OrderExpired($id, $uuid, $checkoutGroupUuid)` (a hook; Payment may later consume it to mark the Payment `Expired` — see E5 note).

**`ExpireAwaitingPaymentJob`** (extends `BaseJob`, **calls `parent::__construct()`**): batches
`orders->awaitingPaymentExpired($window)`, runs each through `ExpireOrderAction`. (Keep it separate
from the existing `ExpireReservationsJob`, which handles the different `Pending` window.)

**Schedule** in `routes/console.php`:
```php
Schedule::job(new \App\Modules\Order\Application\Jobs\ExpireAwaitingPaymentJob)
    ->name('expire-awaiting-payment')->everyMinute()->onOneServer();
// ALSO fix the latent leak: the existing Pending sweep was never scheduled.
Schedule::job(new \App\Modules\Order\Application\Jobs\ExpireReservationsJob)
    ->name('expire-pending-reservations')->everyMinute()->onOneServer();
```

**Tests (`tests/Modules/Order/Feature/PaymentWindowExpiryTest.php`):**
- an `AwaitingPayment` order older than the window → after the job: status `Expired`, its
  reservations released (assert Inventory `available` rose back).
- an `AwaitingPayment` order **within** the window is untouched.
- a `Paid`/`Delivered` order is never touched.
- the sweep is idempotent (running twice doesn't double-release / error).

**Steps:** repo query → action → job → schedule → tests → `make check` → commit + push
`feat(order): expire AwaitingPayment past the payment window + release holds (ADR-072)`.

---

## Phase E4 — hide `Expired` from the customer order list

**`CustomerOrderController::index()`**: add `->whereNot('status', OrderStatus::Expired->value)` (or a
`scopeExcludingStatuses`). **Leave `find()` (single-order-by-uuid) as is** — an expired order stays
viewable by direct link, just not listed. `AwaitingPayment` (still within window) keeps showing.

**Tests:** `GET /orders` omits an `Expired` order but includes `AwaitingPayment`/`Paid`; `GET /orders/{uuid}`
of an expired order still 200s for its owner.

**Steps:** edit → tests → `make check` → commit + push `feat(order): hide Expired orders from the customer list (ADR-072)`.

---

## Phase E5 — the late-payment race: re-reserve-or-refund (the intricate one)

**The race:** PayTR callback verifies a success AFTER the group's orders expired (holds released).
Without handling, `settle()`'s `commit()` throws "no reservation" (caught, commits nothing) and the
listener finds `Expired ↛ Paid` — money taken, nothing fulfilled. Fix it in
**`SettlePaymentCallbackAction::settle()`** (it holds the Inventory port):

For each order in the group, **before** the existing `commit()` loop:
1. If `$order->status === Expired` (or more generally, if the reservation was released — detect by
   the order not being `AwaitingPayment`): attempt to **re-`reserve()`** every line
   (`reserve($sellingOrgUuid, $line->variant_uuid, $line->quantity, $reference)`), tracking success.
2. **Decision (ADR-072, owner):**
   - **All lines across all orders in the group re-reserve** → proceed: `commit()` each (as today),
     and the orders recover to `Paid` (the listener transitions `Expired → Paid`, now legal from E1).
   - **Any line cannot be re-reserved** (stock gone) → **do NOT commit; do NOT mark Paid.** Instead
     mark the `Payment` for **refund** and issue the PayTR refund (reuse the existing refund
     machinery — `RefundLinesAction`/the gateway `refund()`), so the customer's money is returned in
     full. Orders stay `Expired`. Release any lines you *did* re-reserve in this attempt (don't leak
     a partial hold).
3. Keep it **idempotent** (a retried callback must not double-charge/double-refund): gate on
   `Payment` status as `settle()` already does (`awaitsSettlement()`), and make the refund path set a
   terminal Payment state.

**`SettleOrdersOnPayment` (Order side):** no re-reserve here (no Inventory port). Its only change: the
`Expired → Paid` transition is now legal (E1), so when the Payment action recovered the holds and
dispatched `PaymentSucceeded`, the listener transitions normally. When the Payment action chose
refund, it must **not** dispatch `PaymentSucceeded` for those orders (dispatch a
failure/refund outcome instead), so the listener never tries to Paid an unfulfillable order.

**Tests (`tests/Modules/Payment/Feature/LatePaymentAfterExpiryTest.php`):**
- expired order + late success + **stock still available** → order recovers to `Paid`, stock
  committed, no refund.
- expired order + late success + **stock gone** (another buyer took it) → **full refund**, order
  stays `Expired`, no oversell.
- normal (non-expired) success is unaffected (regression).
- callback idempotency: replaying the success callback does not double-commit or double-refund.

**Steps:** settle() re-reserve/refund branch → listener transition allowance → tests → `make check`
→ commit + push `feat(payment): re-reserve-or-refund a payment that succeeds after expiry (ADR-072)`.

---

## Phase E6 — hardening, docs, amendment log

- `make check` full green; `LayeringTest` (Order still imports no module).
- **Amendment log** (`docs/001_Architecture.md`): a row — "Order gains `Expired` + a payment-window
  sweep releasing reservations; late payment re-reserves-or-refunds (ADR-072). Also scheduled the
  previously-unscheduled `Pending` abandonment sweep."
- Update `docs/modules/Order.md` (lifecycle + the new status) and `docs/modules/Payment.md` (the
  late-payment recovery in `SettlePaymentCallbackAction`).
- **Note in the PR/report:** the first scheduled run **self-heals the stuck reservations** (e.g.
  Turuncukasa's) by releasing every abandoned `AwaitingPayment` hold past the window — no separate
  cleanup needed. Confirm on the live server after deploy that a previously-hidden seller's offer
  reappears in the buy box.
- Commit + push `feat(order): E6 hardening + docs (ADR-072)`.

---

## Deploy checklist (live, after merge)

1. `git pull` (server).
2. `php artisan migrate` — if E2 added no migration this is a no-op; run anyway.
3. `php artisan db:seed --class="Database\Modules\Settings\Seeders\SettingsSeeder"` — seeds
   `order.payment_window_minutes = 5`.
4. Ensure the **scheduler is running** (`php artisan schedule:work` or the system cron calling
   `schedule:run` every minute) — the whole feature is inert without it. Verify with
   `php artisan schedule:list` showing `expire-awaiting-payment` every minute.
5. `php artisan optimize:clear`.
6. Within a minute, the stuck `AwaitingPayment` reservations release → the hidden seller (Turuncukasa)
   reappears in the buy box. Verify.

## Out of scope (v1)

- No customer-facing "your order expired" email (the order simply drops off their list; `OrderExpired`
  is emitted as a hook if a notice is wanted later).
- No change to the pre-placement `Pending` window's 30-min value or its `Cancelled` outcome — only its
  scheduling is fixed.
