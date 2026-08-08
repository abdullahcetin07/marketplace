<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OrderReturnContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\ApproveReturnAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CompleteReturnRequestAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\CreateReturnRequestAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Application\Actions\RejectReturnAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Enums\ReturnRequestStatus;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\ReturnRequest;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use App\Modules\Payment\Application\Listeners\OpenSettlementWindows;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\SettlementWindow;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| R3 — the return conversation (ADR-073)
|--------------------------------------------------------------------------
|
| **THE BUYER'S BUTTON USED TO REFUND. NOW IT ASKS.** Three steps, and the money
| is at the third:
|
|   REQUESTED   the buyer names lines and quantities. NOTHING moves.
|   APPROVED    the seller sends a return code. STILL nothing moves.
|   COMPLETED   the parcel is back. THIS is where RefundLinesAction fires.
|
| The assertion that recurs in almost every test below is `PaymentRefund::count()`,
| because "no money moved yet" is the claim ADR-073 makes and the one a regression
| would quietly break.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * PayTR agreeing to send the money back.
 *
 * **PER TEST, NOT IN `beforeEach`, AND THAT IS A REAL TRAP RATHER THAN A STYLE
 * CHOICE.** `Http::fake([$url => …])` MERGES into the existing stubs and the
 * first match wins, so a success fake registered in `beforeEach` cannot be
 * overridden later by a failing one — the refusal test would silently exercise a
 * successful refund and pass for the wrong reason.
 */
function r3GatewayAgrees(): void
{
    Http::fake(['*' => Http::response(['status' => 'success', 'islem_id' => 'IADE-R3'])]);
}

/**
 * A paid, delivered order with an open return window.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{order: Order, seller: string, lines: array<int, array<string, mixed>>}
 */
function returnableOrder(int $quantity = 2): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create([
        'tax_rate_id' => TaxRate::factory()->rate('0.2000')->create()->getKey(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create();

    $offer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: 12_000,
        stockQuantity: 20,
    ));

    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO(
        offerUuid: $offer->uuid,
        quantity: $quantity,
    ));

    $address = app(CreateCustomerAddressAction::class)->run(1, 'musteri', new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
    ));

    $orders = app(CheckoutAction::class)->run(1, 'musteri', new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    $group = $orders[0]->checkout_group_uuid;
    app(PlaceOrderAction::class)->run($group);

    $total = (int) Order::query()->where('checkout_group_uuid', $group)->sum('grand_total_minor');

    $payment = Payment::factory()->create([
        'checkout_group_uuid' => $group,
        'customer_id' => 1,
        'customer_uuid' => 'musteri',
        'amount_minor' => $total,
        'status' => PaymentStatus::Pending,
    ]);

    app(SettlePaymentCallbackAction::class)->run([
        'merchant_oid' => $payment->uuid,
        'status' => 'success',
        'total_amount' => (string) $total,
        'hash' => base64_encode(hash_hmac(
            'sha256',
            $payment->uuid.config('payment.paytr.merchant_salt').'success'.$total,
            (string) config('payment.paytr.merchant_key'),
            true,
        )),
    ]);

    /** @var Order $order */
    $order = Order::query()->where('checkout_group_uuid', $group)->firstOrFail();

    // The parcel arrives — through the listener, so the window's dates are frozen
    // from the event rather than the consuming clock.
    Shipment::query()->where('order_uuid', $order->uuid)->update([
        'status' => ShipmentStatus::Delivered,
        'delivered_at' => now(),
        'delivered_via' => 'buyer',
    ]);

    app(OpenSettlementWindows::class)->handle(new class($order->uuid, $organization->uuid, now()->toIso8601String())
    {
        public function __construct(
            public string $orderUuid,
            public string $sellerOrgUuid,
            public string $deliveredAt,
            public string $deliveredVia = 'buyer',
        ) {}
    });

    $order->forceFill(['status' => OrderStatus::Delivered])->save();

    return [
        'order' => $order->fresh(),
        'seller' => $organization->uuid,
        'lines' => app(App\Core\Domain\Contracts\OrderQueryContract::class)->orderLines($order->uuid),
    ];
}

/*
|--------------------------------------------------------------------------
| Step one — the ask, which moves nothing
|--------------------------------------------------------------------------
*/

it('writes a request and moves NO money — the whole of ADR-073', function (): void {
    r3GatewayAgrees();
    $fixture = returnableOrder(quantity: 2);
    $lineUuid = (string) $fixture['lines'][0]['id'];

    $request = app(CreateReturnRequestAction::class)->run(
        $fixture['order'],
        [$lineUuid => 1],
        1,
        'Ayakkabı küçük geldi',
    );

    expect($request->status)->toBe(ReturnRequestStatus::Requested)
        ->and($request->line_quantities)->toBe([$lineUuid => 1])
        /*
         * **THE ASSERTION THAT DEFINES THE AMENDMENT.** Under ADR-064 this same
         * buyer action produced a `PaymentRefund` row and a PSP call. Now it
         * produces a conversation.
         */
        ->and(PaymentRefund::query()->count())->toBe(0)
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Delivered);

    Http::assertNothingSent();
});

it('refuses a return of a parcel that never arrived', function (): void {
    $fixture = returnableOrder();

    // Not delivered: a parcel in transit is a CANCELLATION, a different operation
    // with different consequences for the seller's stock.
    $fixture['order']->forceFill(['status' => OrderStatus::Paid])->save();

    expect(fn () => app(CreateReturnRequestAction::class)->run(
        $fixture['order']->fresh(),
        [(string) $fixture['lines'][0]['id'] => 1],
        1,
    ))->toThrow(OrderException::class);

    expect(ReturnRequest::query()->count())->toBe(0);
});

it('refuses once the window has closed', function (): void {
    $fixture = returnableOrder();

    SettlementWindow::query()
        ->where('order_uuid', $fixture['order']->uuid)
        ->update(['return_window_ends_at' => now()->subDay()]);

    expect(fn () => app(CreateReturnRequestAction::class)->run(
        $fixture['order'],
        [(string) $fixture['lines'][0]['id'] => 1],
        1,
    ))->toThrow(OrderException::class);
});

it('refuses more units than are returnable, rather than clamping', function (): void {
    $fixture = returnableOrder(quantity: 2);

    /*
     * THREE OF TWO. Silently reducing it would tell the buyer their whole return
     * was accepted and then send back part of it — S4's rule, kept.
     */
    expect(fn () => app(CreateReturnRequestAction::class)->run(
        $fixture['order'],
        [(string) $fixture['lines'][0]['id'] => 3],
        1,
    ))->toThrow(OrderException::class);

    expect(ReturnRequest::query()->count())->toBe(0);
});

it('refuses a second request while one is running — including an APPROVED one', function (): void {
    $fixture = returnableOrder(quantity: 2);
    $lineUuid = (string) $fixture['lines'][0]['id'];

    $request = app(CreateReturnRequestAction::class)->run($fixture['order'], [$lineUuid => 1], 1);

    expect(fn () => app(CreateReturnRequestAction::class)->run($fixture['order'], [$lineUuid => 1], 1))
        ->toThrow(OrderException::class);

    // Approved is still running: the buyer is walking to the cargo desk.
    app(ApproveReturnAction::class)->run($request, 'IADE-123', null, 2);

    expect(fn () => app(CreateReturnRequestAction::class)->run($fixture['order'], [$lineUuid => 1], 1))
        ->toThrow(OrderException::class);

    // A REJECTED one does not block: circumstances change, and the window is
    // still the buyer's.
    app(RejectReturnAction::class)->run($request->fresh()->forceFill([
        'status' => ReturnRequestStatus::Requested,
    ]), 2, 'Tekrar bakalım');

    expect(app(CreateReturnRequestAction::class)->run($fixture['order'], [$lineUuid => 1], 1))
        ->toBeInstanceOf(ReturnRequest::class);
});

/*
|--------------------------------------------------------------------------
| Step two — the answer, which also moves nothing
|--------------------------------------------------------------------------
*/

it('stamps a return code on approval and still moves no money', function (): void {
    r3GatewayAgrees();
    $fixture = returnableOrder();
    $request = app(CreateReturnRequestAction::class)->run(
        $fixture['order'],
        [(string) $fixture['lines'][0]['id'] => 1],
        1,
    );

    $approved = app(ApproveReturnAction::class)->run($request, 'IADE-99887', null, 7);

    expect($approved->status)->toBe(ReturnRequestStatus::Approved)
        ->and($approved->return_code)->toBe('IADE-99887')
        ->and($approved->decided_by)->toBe(7)
        /*
         * **THE DIFFERENCE FROM THE CANCELLATION, IN ONE LINE.** C2's approval
         * refunds, because the goods never left. This one cannot: the parcel is
         * still in the buyer's hands.
         */
        ->and(PaymentRefund::query()->count())->toBe(0)
        ->and($approved->completed_at)->toBeNull();

    Http::assertNothingSent();
});

it('records a rejection with its reason and leaves the sale standing', function (): void {
    r3GatewayAgrees();
    $fixture = returnableOrder();
    $request = app(CreateReturnRequestAction::class)->run(
        $fixture['order'],
        [(string) $fixture['lines'][0]['id'] => 1],
        1,
    );

    $rejected = app(RejectReturnAction::class)->run($request, 7, 'Ürün kullanılmış görünüyor');

    expect($rejected->status)->toBe(ReturnRequestStatus::Rejected)
        ->and($rejected->decision_reason)->toBe('Ürün kullanılmış görünüyor')
        ->and(PaymentRefund::query()->count())->toBe(0)
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Delivered);
});

it('refuses to answer a request that was already answered', function (): void {
    $fixture = returnableOrder();
    $request = app(CreateReturnRequestAction::class)->run(
        $fixture['order'],
        [(string) $fixture['lines'][0]['id'] => 1],
        1,
    );

    app(ApproveReturnAction::class)->run($request, 'IADE-1', null, 7);

    /*
     * RE-APPROVING would replace a return code the buyer is holding a printout
     * of; REJECTING an approved one would withdraw permission for a parcel that
     * may already be in transit.
     */
    expect(fn () => app(ApproveReturnAction::class)->run($request->fresh(), 'IADE-2', null, 7))
        ->toThrow(OrderException::class);

    expect(fn () => app(RejectReturnAction::class)->run($request->fresh(), 7, 'Fikir değişti'))
        ->toThrow(OrderException::class);
});

/*
|--------------------------------------------------------------------------
| Step three — the goods are back, and only now the money
|--------------------------------------------------------------------------
*/

it('refunds when the seller confirms the goods arrived', function (): void {
    r3GatewayAgrees();
    $fixture = returnableOrder(quantity: 2);
    $lineUuid = (string) $fixture['lines'][0]['id'];

    $request = app(CreateReturnRequestAction::class)->run($fixture['order'], [$lineUuid => 2], 1);
    app(ApproveReturnAction::class)->run($request, 'IADE-55', null, 7);

    $completed = app(CompleteReturnRequestAction::class)->run($request->fresh(), $fixture['seller'], 7);

    expect($completed->status)->toBe(ReturnRequestStatus::Completed)
        ->and($completed->completed_at)->not->toBeNull()
        // NOW the money.
        ->and(PaymentRefund::query()->count())->toBe(1)
        /*
         * AND THE ORDER MOVED BY `PaymentRefunded`'s CAUSE, not by this action.
         * `refunded`, never `cancelled` — the parcel was delivered and came back.
         */
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Refunded);
});

it('refuses to complete a return nobody approved', function (): void {
    r3GatewayAgrees();
    $fixture = returnableOrder();
    $request = app(CreateReturnRequestAction::class)->run(
        $fixture['order'],
        [(string) $fixture['lines'][0]['id'] => 1],
        1,
    );

    // Still `requested`: the buyer has been given no way to send anything back,
    // so there is nothing on the seller's shelf to refund against.
    expect(fn () => app(CompleteReturnRequestAction::class)->run($request, $fixture['seller'], 7))
        ->toThrow(OrderException::class);

    expect(PaymentRefund::query()->count())->toBe(0);
});

it('leaves the request APPROVED when the gateway refuses — refund first, stamp second', function (): void {
    $fixture = returnableOrder();
    $lineUuid = (string) $fixture['lines'][0]['id'];

    $request = app(CreateReturnRequestAction::class)->run($fixture['order'], [$lineUuid => 1], 1);
    app(ApproveReturnAction::class)->run($request, 'IADE-77', null, 7);

    // PayTR says no. Registered BEFORE anything succeeds — @see `r3GatewayAgrees()`.
    Http::fake(['*' => Http::response(['status' => 'failed', 'err_msg' => 'iade reddedildi'])]);

    /*
     * `PaymentException`, NOT `Throwable` — Pest reads a non-class string as a
     * MESSAGE substring and `Throwable` is an interface, so the obvious version
     * of this assertion passes a real refusal through as a failure. The trap this
     * suite has now met twice.
     */
    expect(fn () => app(CompleteReturnRequestAction::class)->run($request->fresh(), $fixture['seller'], 7))
        ->toThrow(PaymentException::class);

    /*
     * **THE ORDERING THAT FAILS SAFELY.** Stamped-first would leave a `completed`
     * return beside a buyer whose money never came back — every surface claiming
     * the return finished. This way the request is still `Approved`, which is the
     * truthful state, and the seller may try again once the PSP is happy.
     */
    expect($request->fresh()->status)->toBe(ReturnRequestStatus::Approved)
        ->and($request->fresh()->completed_at)->toBeNull()
        ->and(PaymentRefund::query()->count())->toBe(0)
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Delivered);
});

it('honours the request’s own quantities, re-checked behind the port', function (): void {
    r3GatewayAgrees();
    $fixture = returnableOrder(quantity: 2);
    $lineUuid = (string) $fixture['lines'][0]['id'];

    // The buyer asks for one of the two.
    $request = app(CreateReturnRequestAction::class)->run($fixture['order'], [$lineUuid => 1], 1);
    app(ApproveReturnAction::class)->run($request, 'IADE-88', null, 7);
    app(CompleteReturnRequestAction::class)->run($request->fresh(), $fixture['seller'], 7);

    /*
     * ONE UNIT BACK, ONE STILL SOLD. The order is PARTIALLY refunded, so it does
     * not reach `Refunded` — and the second unit remains returnable, which is the
     * "a second refund of one order is legitimate" rule S4 introduced.
     */
    expect(app(OrderReturnContract::class)->returnableQuantities($fixture['order']->uuid))
        ->toBe([$lineUuid => 1]);
});
