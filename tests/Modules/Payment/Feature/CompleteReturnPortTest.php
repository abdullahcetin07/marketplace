<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OrderReturnContract;
use App\Core\Domain\Contracts\ShipmentQueryContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use App\Modules\Payment\Application\Listeners\OpenSettlementWindows;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\SettlementWindow;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| R2 — the return command port (ADR-073)
|--------------------------------------------------------------------------
|
| **THE PLATFORM'S THIRD COMMAND PORT, AND THE ONE C1 COULD NOT BE.** The
| cancellation port refuses a shipped parcel and hard-codes `cause: cancellation`
| — the two facts a return inverts. Same machinery underneath (`RefundLinesAction`,
| unchanged); different gate, different meaning.
|
| What is pinned here:
|
|   THE GATE       delivered + inside the window, NOT awaiting-handover
|   OWNERSHIP      another seller's order is refused, even with a valid uuid
|   THE CAUSE      `return`, so the order ends `refunded` and never `cancelled`
|   RE-CHECKED     the quantities are decided at completion, not at request time
|   THE CARRIERS   the picker reads Shipping's list, active only
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Http::fake(['*' => Http::response(['status' => 'success', 'islem_id' => 'IADE-R2'])]);
});

/**
 * A paid, DELIVERED order with its return window open.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{order: Order, seller: string, variant: string, lines: array<int, array<string, mixed>>, payment: Payment}
 */
function deliveredReturnableOrder(int $quantity = 2, int $priceMinor = 12_000): array
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
        priceMinor: $priceMinor,
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

    return [
        'order' => $order->fresh(),
        'seller' => $organization->uuid,
        'variant' => $variant->uuid,
        'lines' => app(App\Core\Domain\Contracts\OrderQueryContract::class)->orderLines($order->uuid),
        'payment' => $payment->fresh(),
    ];
}

/**
 * The parcel arrives, through the listener — the dates being frozen from the
 * event rather than the consuming clock is half of what makes the window
 * trustworthy.
 */
function deliverForReturn(string $orderUuid, string $sellerOrgUuid): void
{
    Shipment::query()->where('order_uuid', $orderUuid)->update([
        'status' => ShipmentStatus::Delivered,
        'delivered_at' => now(),
        'delivered_via' => 'buyer',
    ]);

    app(OpenSettlementWindows::class)->handle(new class($orderUuid, $sellerOrgUuid, now()->toIso8601String())
    {
        public function __construct(
            public string $orderUuid,
            public string $sellerOrgUuid,
            public string $deliveredAt,
            public string $deliveredVia = 'buyer',
        ) {}
    });
}

/*
|--------------------------------------------------------------------------
| The gate — delivered and in time, which is the OPPOSITE of C1's
|--------------------------------------------------------------------------
*/

it('refunds a delivered order’s lines when the seller completes the return', function (): void {
    $fixture = deliveredReturnableOrder(quantity: 2);
    deliverForReturn($fixture['order']->uuid, $fixture['seller']);

    $lineUuid = (string) $fixture['lines'][0]['id'];

    app(OrderReturnContract::class)->completeReturnBySeller(
        $fixture['order']->uuid,
        $fixture['seller'],
        [$lineUuid => 2],
    );

    /*
     * **THE CAUSE IS THE ASSERTION.** The money is identical to a cancellation's;
     * what differs is what it MEANS — and `cause` is the only thing that can say
     * so. A buyer told their delivered parcel was "iptal edildi" is a support
     * ticket.
     */
    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and(PaymentRefund::query()->count())->toBe(1);

    // AND THE UNITS CAME BACK. A return that refunds without restocking leaves the
    // seller short of both money and goods.
    $stock = StockItem::query()->where('variant_uuid', $fixture['variant'])->firstOrFail();
    expect($stock->on_hand)->toBe(20);
});

it('refuses when the parcel was never delivered — the gate C1 has backwards', function (): void {
    $fixture = deliveredReturnableOrder();
    // No delivery, so no settlement window. The shipment is still `pending`, which
    // is exactly the state C1's `isAwaitingHandover` would have ACCEPTED.

    expect(fn () => app(OrderReturnContract::class)->completeReturnBySeller(
        $fixture['order']->uuid,
        $fixture['seller'],
        [(string) $fixture['lines'][0]['id'] => 1],
    ))->toThrow(PaymentException::class);

    expect(PaymentRefund::query()->count())->toBe(0);
});

it('refuses once the return window has closed', function (): void {
    $fixture = deliveredReturnableOrder();
    deliverForReturn($fixture['order']->uuid, $fixture['seller']);

    // The window is a real column, not a clock read at call time — so closing it
    // is a fact about the order rather than about the test's timezone.
    SettlementWindow::query()
        ->where('order_uuid', $fixture['order']->uuid)
        ->update(['return_window_ends_at' => now()->subDay()]);

    expect(app(OrderReturnContract::class)->isReturnOpen($fixture['order']->uuid))->toBeFalse();

    expect(fn () => app(OrderReturnContract::class)->completeReturnBySeller(
        $fixture['order']->uuid,
        $fixture['seller'],
        [(string) $fixture['lines'][0]['id'] => 1],
    ))->toThrow(PaymentException::class);
});

it('refuses another seller’s order, even with a real uuid', function (): void {
    $fixture = deliveredReturnableOrder();
    deliverForReturn($fixture['order']->uuid, $fixture['seller']);

    $stranger = Organization::factory()->create();

    /*
     * OWNERSHIP IS RE-CHECKED BEHIND THE PORT even though the panel scopes its own
     * query — a panel's tenancy is a query somebody can get wrong, and this is the
     * side that cannot be.
     */
    expect(fn () => app(OrderReturnContract::class)->completeReturnBySeller(
        $fixture['order']->uuid,
        $stranger->uuid,
        [(string) $fixture['lines'][0]['id'] => 1],
    ))->toThrow(PaymentException::class);

    expect(PaymentRefund::query()->count())->toBe(0);
});

it('refuses a quantity beyond what is still returnable', function (): void {
    $fixture = deliveredReturnableOrder(quantity: 2);
    deliverForReturn($fixture['order']->uuid, $fixture['seller']);

    $lineUuid = (string) $fixture['lines'][0]['id'];

    // Three of two. A refusal, never a clamp — S4's rule, unchanged.
    expect(fn () => app(OrderReturnContract::class)->completeReturnBySeller(
        $fixture['order']->uuid,
        $fixture['seller'],
        [$lineUuid => 3],
    ))->toThrow(PaymentException::class);
});

/*
|--------------------------------------------------------------------------
| The reads the request form is built from
|--------------------------------------------------------------------------
*/

it('reports what is still returnable, and shrinks it as units go back', function (): void {
    $fixture = deliveredReturnableOrder(quantity: 2);
    deliverForReturn($fixture['order']->uuid, $fixture['seller']);

    $port = app(OrderReturnContract::class);
    $lineUuid = (string) $fixture['lines'][0]['id'];

    expect($port->returnableQuantities($fixture['order']->uuid))->toBe([$lineUuid => 2]);

    // One shoe goes back today.
    $port->completeReturnBySeller($fixture['order']->uuid, $fixture['seller'], [$lineUuid => 1]);

    /*
     * **THE NUMBER THAT REPLACED A UNIQUE INDEX (S4).** One left, not two and not
     * zero — and this is why the quantities are re-checked at COMPLETION rather
     * than trusted from the request: a return request can sit for days, and an
     * admin may refund one of its lines in the meantime.
     */
    expect($port->returnableQuantities($fixture['order']->uuid))->toBe([$lineUuid => 1]);

    // And the second one is still allowed — a second refund of one order is
    // legitimate, which is exactly what P5's constraint used to forbid.
    $port->completeReturnBySeller($fixture['order']->uuid, $fixture['seller'], [$lineUuid => 1]);

    expect($port->returnableQuantities($fixture['order']->uuid))->toBe([]);
});

it('answers empty rather than throwing for an order it cannot see', function (): void {
    $port = app(OrderReturnContract::class);

    /*
     * A SURFACE THAT RENDERS NOTHING RENDERS THE RIGHT THING. And the non-uuid
     * case is the ADR-059 trap: `settlement_windows.order_uuid` is a native uuid
     * column on PostgreSQL, so a slug reaching it is a 500 rather than a miss.
     */
    expect($port->returnableQuantities('magaza-adi'))->toBe([])
        ->and($port->returnableQuantities('9d1f0f1e-0000-4000-8000-000000000000'))->toBe([])
        ->and($port->isReturnOpen('magaza-adi'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The carrier list — Shipping's, read through the port
|--------------------------------------------------------------------------
*/

it('offers the seller the active carriers, and only those', function (): void {
    $off = CargoCompany::query()->first();

    if ($off === null) {
        $this->markTestSkipped('No carriers seeded.');
    }

    $carriers = app(ShipmentQueryContract::class)->activeCargoCompanies();

    expect($carriers)->not->toBeEmpty()
        ->and($carriers)->toHaveKey($off->uuid)
        // uuid => name, primitives only: a Collection here would tempt a caller
        // into chaining Eloquent behind the port.
        ->and($carriers[$off->uuid])->toBe($off->name);

    // An operator switches one off; it leaves the picker.
    $off->forceFill(['is_active' => false])->save();

    expect(app(ShipmentQueryContract::class)->activeCargoCompanies())->not->toHaveKey($off->uuid);
});
