# BUILD — Return-request redesign (seller approves + completes; refund at the end) + admin-cancel guard

**ADR:** ADR-073 (amends ADR-064). **Owner-approved.**

**Today (wrong for physical goods):** a customer return **instantly refunds** via PSP
(`RequestReturnAction::run()` → `RefundLinesAction` immediately). **New flow:** customer
creates a return **REQUEST** (no money) → seller **APPROVES + shares an iade kodu** →
customer ships it back → seller **"İadeyi tamamla"** → **only then** the refund fires. It is
the post-delivery mirror of ADR-065's pre-shipment cancellation request.

**The money machine (`RefundLinesAction`) does NOT change — only its trigger moves** from the
customer's request to the seller's completion, through a new Core command port.

Also bundled: the small **admin cancel-button visibility guard** (R6).

Build phases in order; each ends `make check` green + commit + push. Contradiction → STOP and
report (ADR-018). **Storefront (R8) is the desktop session's job** — §R8 is the contract only.

---

## Non-negotiables (restated)

- `declare(strict_types=1)`; no `dd/dump/die`. **Money = integer kuruş.** A refund names ORDERS
  OR LINES, never an amount.
- **Order imports NO module** — Payment/Shipping reached via Core contracts + class-string events
  only (`LayeringTest`). The `ReturnRequest` lives in Order; the refund fires through a Core port.
- **Refund-first-then-stamp**: the only ordering that fails safely (ADR-065). Complete the refund
  through the port, THEN stamp the request `Completed`.
- `make check` green before any phase is "done".

---

## Verified facts (don't re-derive)

- **`RefundLinesAction`** (`app/Modules/Payment/Application/Actions/RefundLinesAction.php`) is
  **input-agnostic** — `handle()` takes a `ReturnRequestDTO {orderUuid, quantities:{lineUuid→int},
  reason?, actorId?, cause: RefundCause}`. It does: PSP refund FIRST (`gatewayRejected` throws,
  nothing written) → `PaymentRefund`/`PaymentRefundLine` rows → `SellerLedgerEntry` reversal →
  Inventory `restock()` → `Payment` → Refunded/PartiallyRefunded → dispatches `PaymentRefunded`
  (`cause`, `orderUuids` when fully returned) in `after()`. **Reuse UNCHANGED**, feed it a DTO with
  `cause: RefundCause::Return`.
- **Returnable read:** `RefundableLines::price($line, $qty): ?self` — `remaining = quantity −
  PaymentRefundLine::refundedQuantityFor(lineId)`; null if `qty ≤ 0 || qty > remaining`.
  `ReturnController::show()` already exposes `returnable_quantity` per line.
- **Return window:** `SettlementWindow` (`settlement_windows`, keyed `order_uuid`),
  `isReturnOpen()` = `return_window_ends_at->isFuture()`; created on `ShipmentDelivered`
  (`OpenSettlementWindows` listener). "No window" = not delivered = refuse.
- **Entity to MIRROR:** `CancellationRequest` (ADR-065 C2) — model
  `app/Modules/Order/Domain/Models/CancellationRequest.php`, enum
  `CancellationRequestStatus` (`Pending/Approved/Rejected`, `isOpen()`, `color()/label()`),
  migration `..._create_cancellation_requests_table.php` (partial unique `one_open` on pgsql),
  seller resource `.../Filament/Seller/Resources/CancellationRequestResource.php`, customer
  controller `CancellationRequestController` (store/show, `owned()` scope), API resource, factory.
  **ReturnRequest adds: line quantities + `return_code` + `cargo_company_uuid`.**
- **C1 port** `App\Core\Domain\Contracts\OrderCancellationContract` (`cancelLinesBySeller` +
  `cancellableQuantities`), impl `Payment\Infrastructure\Commands\OrderCancellation` →
  `CancelOrderLinesAction` (builds `ReturnRequestDTO` cause=Cancellation, calls `RefundLinesAction`).
  **CANNOT be reused** for completion: it `assertAwaitingHandover` (refuses shipped) + hard-codes
  Cancellation. Build a sibling port instead (R2).
- **Carriers:** `CargoCompany` (Shipping, `cargo_companies`: code/name/tracking_url_template/
  is_active/sort_order; scopes `active()`, `ordered()`). Order may not import it → expose via
  `ShipmentQueryContract` (R2). Seller ship-form widget shape to copy:
  `Select::make('cargo_company_uuid')->options(CargoCompany::active()->ordered()->pluck('name','uuid'))`
  in `Shipping/.../Seller/Resources/ShipmentResource.php`.
- **Admin cancel bug:** `Order/.../Filament/Resources/OrderResource.php` **line 308**
  `->visible(fn ($record) => auth()->user()?->can('cancel',$record)===true)`; Super Admin bypasses
  `OrderPolicy::before()` so it shows on delivered orders → `CancelOrderAction` throws. Seller
  `OrderResource.php` line 331 has the same closure (not exploitable — sellers aren't super admins —
  but harden for consistency).
- **Storefront today:** `GET /orders/{order}/return` (`ReturnController@show`: returnable lines +
  window) — KEEP. `POST /orders/{order}/return` (`@store` → immediate refund) — REPLACE. Session-api
  `fetchOrderReturn` / `requestReturn`. Cancellation analogue: `fetchCancellationRequest` /
  `requestCancellation` ("201 = talep alındı, satıcı onayında").
- Tests: **Pest**, Feature/Modules get `RefreshDatabase`; `seedPlatform()`, `actingAsCustomer()`,
  `actingAsSeller()`, `actingAsAdmin()`.

---

## Phase R1 — `ReturnRequest` entity (Order)

**`Domain/Enums/ReturnRequestStatus.php`** — `enum ReturnRequestStatus: string`:
`Requested='requested'`, `Approved='approved'`, `Rejected='rejected'`, `Completed='completed'`.
Helpers: `isOpen()` (=`Requested || Approved` — a non-terminal, blocks a second open request),
`color()`, `label()` (lang keys). Transitions (enforce in the actions, not a table):
`Requested → Approved|Rejected`, `Approved → Completed`, `Rejected`/`Completed` terminal.

**`Domain/Models/ReturnRequest.php`** — `use Auditable, HasUuid, HasFactory` (mirror
CancellationRequest):
```php
protected $fillable = [
  'order_uuid','requested_by','customer_id','reason','status',
  'line_quantities','return_code','cargo_company_uuid','decision_reason',
  'decided_by','decided_at','completed_by','completed_at',
];
protected function casts(): array {
  return ['status' => ReturnRequestStatus::class, 'line_quantities' => 'array',
          'decided_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
}
public function order() { return $this->belongsTo(Order::class, 'order_uuid', 'uuid'); }
// scopes: scopeOpen($q) => whereIn('status', [Requested, Approved]); scopeForOrder($q, $uuid);
```

**Migration `database/Modules/Order/migrations/*_create_return_requests_table.php`:**
```php
$table->bigIncrements('id');
$table->uuid('uuid')->unique();
$table->uuid('order_uuid')->index();
$table->unsignedBigInteger('requested_by');          // customer user id
$table->unsignedBigInteger('customer_id');           // for ownership scoping
$table->text('reason')->nullable();
$table->string('status', 20)->default('requested');
$table->json('line_quantities');                     // {order_line_uuid: qty}
$table->string('return_code')->nullable();           // set on approval
$table->uuid('cargo_company_uuid')->nullable();      // set on approval
$table->text('decision_reason')->nullable();         // rejection reason
$table->unsignedBigInteger('decided_by')->nullable();
$table->timestampTz('decided_at')->nullable();
$table->unsignedBigInteger('completed_by')->nullable();
$table->timestampTz('completed_at')->nullable();
$table->timestampsTz();
$table->index('customer_id');
```
Plus a **partial UNIQUE "one open return per order"** (pgsql), mirroring
`cancellation_requests_one_open`: `WHERE status IN ('requested','approved')`. On SQLite the action's
own check is the guard (same note as CancellationRequest).

**Factory** — states `requested()`, `approved()`, `rejected()`, `completed()`, `forOrder($uuid)`,
`forCustomer($id)`.

**Tests:** enum transitions/`isOpen`; model casts + `line_quantities` round-trips; the partial-unique
blocks a second open request (pgsql path).

**Steps:** enum → model → migration → factory → tests → `make check` → commit + push
`feat(order): ReturnRequest entity (ADR-073)`.

---

## Phase R2 — Core `OrderReturnContract` + Payment impl + carrier list

**`app/Core/Domain/Contracts/OrderReturnContract.php`** (mirror C1's `OrderCancellationContract`):
```php
/** The return window is still open (delivered + within return_days). */
public function isReturnOpen(string $orderUuid): bool;
/** Units still returnable per line: order-line-uuid ⇒ remaining count. */
public function returnableQuantities(string $orderUuid): array;
/**
 * Fire the refund for a completed return (goods received). Reuses RefundLinesAction
 * with cause=Return; guards delivered + window, NOT awaiting-handover (that is C1).
 */
public function completeReturnBySeller(
    string $orderUuid, string $sellerOrgUuid, array $quantities, ?int $actorId = null
): void;
```

**Payment impl** `app/Modules/Payment/Infrastructure/Commands/OrderReturn.php` (thin adapter) →
a new `app/Modules/Payment/Application/Actions/CompleteReturnAction.php` (or reuse the pieces of
`RequestReturnAction`): asserts `SettlementWindow::isReturnOpen($orderUuid)` (delivered + window),
builds `ReturnRequestDTO(orderUuid, quantities, cause: RefundCause::Return, actorId)`, calls
`RefundLinesAction::run($dto)`. `returnableQuantities` = the same computation `ReturnController@show`
uses (`RefundableLines`/`refundedQuantityFor`). `isReturnOpen` = the `SettlementWindow` read.
Bind the contract in `PaymentServiceProvider`.

**Extend `ShipmentQueryContract`** (Shipping) with:
```php
/** Active carriers for a return-code picker: uuid ⇒ name. */
public function activeCargoCompanies(): array;
```
impl in Shipping's `ShipmentQuery` (`CargoCompany::active()->ordered()->pluck('name','uuid')->all()`).
Recorded in the amendment log as a read a later surface required.

**Tests:** `completeReturnBySeller` on a delivered, in-window order refunds the named quantities
(assert `PaymentRefunded` cause=return, Inventory restocked); refuses when window closed; refuses a
quantity beyond returnable. `isReturnOpen`/`returnableQuantities` correct.

**Steps:** contract → impl → provider bind → ShipmentQueryContract extension → tests → `make check`
→ commit + push `feat(core): OrderReturnContract — seller completes a return refund (ADR-073)`.

---

## Phase R3 — Order actions + policy

`app/Modules/Order/Application/Actions/` (all `BaseAction` unless noted):
- **`CreateReturnRequestAction`** (`handle(Order $order, array $lineQuantities, ?string $reason, User $customer): ReturnRequest`):
  guard the order is `Delivered` **and** `app(OrderReturnContract::class)->isReturnOpen($order->uuid)`;
  validate every requested qty ≤ `returnableQuantities()[lineUuid]`; refuse if an open request exists
  (the partial unique + an explicit check); create the row `Requested`. Emit `ReturnRequested`.
- **`ApproveReturnAction`** (`handle(ReturnRequest $req, string $returnCode, ?string $cargoCompanyUuid, int $actorId): ReturnRequest`):
  guard `status === Requested`; stamp `Approved` + `return_code` + `cargo_company_uuid` +
  `decided_by`/`decided_at`. Emit `ReturnApproved`. **No money.**
- **`RejectReturnAction`** (`handle(ReturnRequest $req, int $actorId, string $reason): ReturnRequest`):
  guard `Requested`; stamp `Rejected` + `decision_reason` + decided fields. **No money.**
- **`CompleteReturnAction`** — **NOT a plain BaseAction transaction** (it drives an external refund
  through the port, like `ApproveCancellationAction`): guard `status === Approved`; call
  `app(OrderReturnContract::class)->completeReturnBySeller($req->order_uuid, $sellerOrgUuid,
  $req->line_quantities, $actorId)` (**refund FIRST**); THEN stamp `Completed` + `completed_by`/
  `completed_at`. The order → `Refunded` happens via `PaymentRefunded` (cause=return) as today —
  do not set order status here.

**`Domain/Events/`** — `ReturnRequested`, `ReturnApproved`, `ReturnRejected`, `ReturnCompleted`
(ids+uuids only; hooks for future notifications, none in v1).

**`Presentation/Policies/ReturnRequestPolicy.php`** (or fold into OrderPolicy): a customer may
`create` a return for their own delivered order (`owns`), and `view`/`delete` their own request;
a seller may `decideReturn` (approve/reject/complete) a request whose order's `store_uuid` ∈ their
live stores (resolve via OrganizationAuthorizationContract + StoreQueryContract, like C2).

**Tests:** create refuses when window closed / qty over returnable / an open request exists; approve
stamps code+carrier without money; complete refunds then stamps Completed (refund-first ordering:
a gateway failure leaves the request `Approved`, not `Completed`, and no money moved).

**Steps:** actions → events → policy → tests → `make check` → commit + push
`feat(order): return request actions + policy (ADR-073)`.

---

## Phase R4 — Customer API

`app/Modules/Order/Presentation/Controllers/Api/ReturnRequestController.php` (mirror
`CancellationRequestController`):
- `store(CreateReturnRequestRequest, string $order)` — authorize `create`; build line quantities from
  the request; `CreateReturnRequestAction::run(...)`; **201** with `ReturnRequestResource`
  ("iade talebi alındı", NOT a refund).
- `show(string $order)` — the order's current (open or latest) return request, 404 when none.
- `owned()` scope by `customer_id` + uuid-shape (copy C2).

**`CreateReturnRequestRequest`** (BaseRequest): authorize actor is Customer; rules
`lines` required array, `lines.*.id` uuid, `lines.*.quantity` integer min:1, `reason` nullable
string max:1000.

**`ReturnRequestResource`** — `{id, order_id, status, status_label, reason, lines:[{id, quantity}],
return_code, cargo:{name}|null, decision_reason, decided_at, completed_at, created_at}` (resolve
carrier name via `ShipmentQueryContract::activeCargoCompanies()` for display).

**Routes** (`routes/api.php`, customer-auth group, near the cancellation-request routes):
```php
Route::get('orders/{order}/return-request',  [ReturnRequestController::class, 'show'])->name('orders.return-request.show');
Route::post('orders/{order}/return-request', [ReturnRequestController::class, 'store'])->name('orders.return-request.store');
```
**KEEP** `GET /orders/{order}/return` (`ReturnController@show`, returnable lines + window — feeds the
form). **REMOVE the customer immediate-refund** `POST /orders/{order}/return` (`ReturnController@store`)
and `RequestReturnAction`'s customer entry — the refund is now the seller's completion. (Leave the
admin refund surface `RefundController` untouched.)

**Tests:** a delivered order's owner POSTs a return-request → 201 pending, no money moved (assert no
`PaymentRefund` row); GET returns it; another customer's order → 403/404; qty over returnable → 422.

**Steps:** controller → request → resource → routes (add new, remove old customer refund) → tests →
`make check` → commit + push `feat(order): customer return-request api (ADR-073)`.

---

## Phase R5 — Seller Filament resource (approve + code / reject / complete)

`app/Modules/Order/Presentation/Filament/Seller/Resources/ReturnRequestResource.php` (mirror
`CancellationRequestResource`): inbox, default filter `Requested`, nav badge = open count,
`canCreate/canEdit/canDelete = false`, tenancy `getEloquentQuery()` →
`whereHas('order', fn ($q) => $q->whereIn('store_uuid', OrderResource::sellerStoreUuids()))`.

Actions:
- **`approveAction()`** — visible when `status === Requested && can('decideReturn', $record->order)`;
  form `[TextInput::make('return_code')->required()->maxLength(100),
  Select::make('cargo_company_uuid')->required()->options(app(ShipmentQueryContract::class)->activeCargoCompanies())]`;
  `->action(fn ($record, $data) => app(ApproveReturnAction::class)->run($record, $data['return_code'],
  $data['cargo_company_uuid'], (int) auth()->id()))` + success Notification.
- **`rejectAction()`** — `Textarea::make('decision_reason')->required()`; `RejectReturnAction`.
- **`completeAction()`** — visible when `status === Approved && can('decideReturn', ...)`;
  `->requiresConfirmation()->modalDescription('Ürünü teslim aldığınızı ve ücret iadesinin
  başlatılacağını onaylıyor musunuz?')`; `->action(fn ($record) =>
  self::decide(fn () => app(CompleteReturnAction::class)->run($record, /*sellerOrgUuid*/, (int) auth()->id()),
  'İade tamamlandı, ücret iadesi başlatıldı.'))` — wrap in a try/catch (`decide`) surfacing a PSP
  failure as a warning Notification (so a `gatewayRejected` tells the seller, doesn't 500).

**Register** in `SellerPanelProvider` `->resources([...])`. **Permissions:**
`PermissionRegistry::ability('order.decide_return', [UserType::Seller])` (+ Seller Employee via
`RolePermissionSeeder`, like the cancellation decision); the resource's `canViewAny` on
`order.view_any` (sellers already have it).

**Tests:** a seller sees/acts only on return requests for their stores; approve sets code+carrier;
complete triggers the refund (assert `PaymentRefunded` cause=return) and marks `Completed`; another
seller's request is unreachable; a Seller Employee can decide.

**Steps:** resource → register → permissions (`make permissions` + seeder) → tests → `make check` →
commit + push `feat(order): seller return panel — approve/reject/complete (ADR-073)`.

---

## Phase R6 — Admin cancel-button guard (bundled fix)

**`app/Modules/Order/Presentation/Filament/Resources/OrderResource.php` line 308** — add the status
guard so Super Admin doesn't see plain "İptal" on a paid/delivered order (which `CancelOrderAction`
would refuse):
```php
->visible(fn (Order $record): bool =>
    $record->status->isCancellableWithoutRefund()
    && auth()->user()?->can('cancel', $record) === true)
```
Apply the **same** change to the seller `OrderResource.php` line 331 (defense in depth). A paid/
delivered order is undone by a **refund** (return, or admin refund), never plain cancel.

**Test:** the cancel action is not visible on a `Delivered`/`Paid` order even for Super Admin; still
visible on `Pending`/`AwaitingPayment`.

**Steps:** two edits → test → `make check` → commit + push `fix(order): hide plain-cancel on paid/delivered orders (ADR-065)`.

---

## Phase R7 — Hardening, docs, PayTR note

- `make check` green; `LayeringTest` (Order still imports no module — the refund goes through
  `OrderReturnContract`).
- **Amendment log** (`docs/001_Architecture.md`): rows for ADR-073 (return becomes request→approve→
  complete; refund at completion; `OrderReturnContract`; `ShipmentQueryContract::activeCargoCompanies`)
  and the admin-cancel guard.
- Update `docs/modules/Payment.md` §8 (the return no longer refunds on request) and add
  `docs/modules/Order.md` coverage of `ReturnRequest`.
- **⚠️ PayTR refund note (put in the PR/report):** completion fires the real PayTR refund. The sandbox
  refund is currently failing ("Ödeme sağlayıcısı reddetti"). **Verify PayTR refund capability/permission
  in the merchant panel** (or that sandbox refunds are enabled) — otherwise the "İadeyi tamamla" step
  stalls in production. Not solved by this work order; must be resolved before go-live.
- Commit + push `feat(order): R7 hardening + docs (ADR-073)`.

---

## R8 — Storefront (DESKTOP session — NOT this work order)

The customer flow reworks from "instant refund" to "request → track status" (mirroring the cancel
request). API contract:
- `GET /orders/{order}/return` → returnable lines + window (unchanged; feeds the form).
- `POST /orders/{order}/return-request` `{lines:[{id,quantity}], reason?}` → 201 pending ReturnRequest.
- `GET /orders/{order}/return-request` → `{id, status, status_label, reason, lines, return_code,
  cargo:{name}|null, decision_reason, decided_at, completed_at, created_at}` (404 when none).

Storefront: replace the current instant-refund `ReturnControl` with a request flow — "İade talebi
oluştur" (pick lines+qty+reason) → status display: "satıcı onayında" / "İade onaylandı — iade kodu:
XXX, {kargo} ile gönderin" / "İade tamamlandı (ücret iade edildi)" / "reddedildi: {gerekçe}". Add
`fetchOrderReturnRequest` / `createReturnRequest` to session-api; update the `OrderReturn` types.
