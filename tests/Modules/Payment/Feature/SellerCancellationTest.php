<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OrderCancellationContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CancelOrderAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CancelOrderDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Payment\Application\Actions\CancelOrderLinesAction;
use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\PaymentRefundLine;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Seller line-level cancellation (ADR-065, C1)
|--------------------------------------------------------------------------
|
| **THE MIRROR OF THE RETURN, AND THE POINT IS HOW LITTLE IS NEW.** The money is
| `RefundLinesAction` unchanged — same kuruş, same proportional commission, same
| restock, same two ledger entries. What C1 adds is a trigger and a gate:
|
|   THE GATE      the parcel must still be awaiting handover. Not a time window —
|                 a shipment STATE, read through a Core contract, and a missing
|                 shipment refuses rather than assumes
|   OWNERSHIP     a seller cancels their own org's order and nobody else's
|   CAUSE         the refund says WHY, and that alone decides whether the order
|                 ends `cancelled` or `refunded` and the parcel `cancelled` or
|                 `returned`
|   THE OLD LEVER `CancelOrderAction` must NOT reach a paid order now that
|                 `Paid → Cancelled` is a legal edge — it would cancel a purchase
|                 with the money still taken
|
| A basket here is TWO UNITS OF ONE LINE at 12 000 kuruş, 18% commission.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A paid order with a `pending` parcel — the state C1 operates on.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{payment: Payment, order: Order, seller: string, variant: string, lines: array<int, array<string, mixed>>}
 */
function cancelFixture(int $quantity = 2, int $priceMinor = 12_000): array
{
    $customerId = 1;

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

    app(AddCartItemAction::class)->run($customerId, 'musteri', new AddCartItemDTO(
        offerUuid: $offer->uuid,
        quantity: $quantity,
    ));

    $address = app(CreateCustomerAddressAction::class)->run($customerId, 'musteri', new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
    ));

    $orders = app(CheckoutAction::class)->run($customerId, 'musteri', new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    $group = $orders[0]->checkout_group_uuid;

    app(PlaceOrderAction::class)->run($group);

    $total = (int) Order::query()->where('checkout_group_uuid', $group)->sum('grand_total_minor');

    $payment = Payment::factory()->create([
        'checkout_group_uuid' => $group,
        'customer_id' => $customerId,
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
        'payment' => $payment->fresh(),
        'order' => $order->fresh(),
        'seller' => $organization->uuid,
        'variant' => $variant->uuid,
        'lines' => app(App\Core\Domain\Contracts\OrderQueryContract::class)->orderLines($order->uuid),
    ];
}

/**
 * PayTR agreeing to send the money back.
 */
function cancelGatewayAgrees(): void
{
    Http::fake(['*' => Http::response(['status' => 'success', 'islem_id' => 'IPTAL-C1'])]);
}

/*
|--------------------------------------------------------------------------
| The money is the return's, unchanged
|--------------------------------------------------------------------------
*/

it('refunds and restocks one of two units the seller cannot send', function (): void {
    $fixture = cancelFixture(quantity: 2);
    $seller = $fixture['seller'];
    cancelGatewayAgrees();

    // 24 000 sold, 18% taken.
    expect(SellerLedgerEntry::balanceFor($seller))->toBe(19_680);

    app(CancelOrderLinesAction::class)->run(
        orderUuid: $fixture['order']->uuid,
        sellerOrgUuid: $seller,
        quantities: [$fixture['lines'][0]['id'] => 1],
        reason: 'Depoda kalmamış',
    );

    /*
     * IDENTICAL TO THE RETURN, TO THE KURUŞ (ADR-065: reuse, not reinvention).
     * One unit's KDV-inclusive price back to the buyer, one unit's share of the
     * frozen commission back to the seller.
     */
    $refund = PaymentRefund::query()->firstOrFail();

    expect($refund->amount_minor)->toBe(12_000)
        ->and($refund->reason)->toBe('Depoda kalmamış')
        ->and(SellerLedgerEntry::balanceFor($seller))->toBe(9_840);

    $item = StockItem::query()
        ->where('selling_org_uuid', $seller)
        ->where('variant_uuid', $fixture['variant'])
        ->firstOrFail();

    // 20 − 2 sold + 1 back.
    expect($item->on_hand)->toBe(19);

    /*
     * THE ORDER IS STILL PAID AND THE PARCEL IS STILL PENDING. Half the sale
     * stands, so the seller still has one unit to put in a box.
     */
    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(Shipment::query()->where('order_uuid', $fixture['order']->uuid)->value('status'))
        ->toBe(ShipmentStatus::Pending)
        ->and($fixture['payment']->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('cancels the order and closes the parcel when every unit goes', function (): void {
    $fixture = cancelFixture(quantity: 2);
    cancelGatewayAgrees();

    app(CancelOrderLinesAction::class)->run(
        orderUuid: $fixture['order']->uuid,
        sellerOrgUuid: $fixture['seller'],
        quantities: [$fixture['lines'][0]['id'] => 2],
        reason: 'Ürün tedarik edilemiyor',
    );

    $order = $fixture['order']->fresh();
    $shipment = Shipment::query()->where('order_uuid', $order->uuid)->firstOrFail();

    /*
     * `Cancelled`, NOT `Refunded`, and the distinction is the whole reason the
     * event carries a cause. The money moved either way; these goods never left
     * the seller, and a list telling the buyer their parcel was "iade edildi"
     * when nobody ever packed it is a support ticket.
     */
    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancelled_at)->not->toBeNull()
        // The seller owed the buyer a sentence, and it reached the order screen.
        ->and($order->cancellation_reason)->toBe('Ürün tedarik edilemiyor')
        ->and($shipment->status)->toBe(ShipmentStatus::Cancelled)
        ->and($shipment->cancelled_at)->not->toBeNull()
        ->and($shipment->returned_at)->toBeNull()
        ->and($fixture['payment']->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and(SellerLedgerEntry::balanceFor($fixture['seller']))->toBe(0);

    $item = StockItem::query()
        ->where('selling_org_uuid', $fixture['seller'])
        ->where('variant_uuid', $fixture['variant'])
        ->firstOrFail();

    expect($item->on_hand)->toBe(20);
});

/*
|--------------------------------------------------------------------------
| The gate
|--------------------------------------------------------------------------
*/

it('refuses once the parcel is with a carrier', function (): void {
    $fixture = cancelFixture(quantity: 2);
    cancelGatewayAgrees();

    Shipment::query()->where('order_uuid', $fixture['order']->uuid)->update([
        'status' => ShipmentStatus::Shipped,
        'shipped_at' => now(),
    ]);

    /*
     * ADR-065'S WHOLE GATE, and it is a shipment STATE rather than a clock: once
     * the seller has handed the box over they have spent the effort, and the
     * buyer's route is the return (ADR-064).
     */
    expect(fn () => app(CancelOrderLinesAction::class)->run(
        orderUuid: $fixture['order']->uuid,
        sellerOrgUuid: $fixture['seller'],
        quantities: [$fixture['lines'][0]['id'] => 1],
    ))->toThrow(PaymentException::class);

    expect(PaymentRefund::query()->count())->toBe(0);
});

it('refuses when the order has no parcel at all', function (): void {
    $fixture = cancelFixture(quantity: 2);
    cancelGatewayAgrees();

    Shipment::query()->where('order_uuid', $fixture['order']->uuid)->delete();

    /*
     * A MISSING SHIPMENT IS A REFUSAL, NOT AN ASSUMPTION. Reading the absence of
     * a row as "not shipped yet" is the most expensive guess available — it
     * refunds a parcel that may already be with a carrier. `shipping:backfill`
     * is the fix, and it is the smaller problem.
     */
    expect(fn () => app(CancelOrderLinesAction::class)->run(
        orderUuid: $fixture['order']->uuid,
        sellerOrgUuid: $fixture['seller'],
        quantities: [$fixture['lines'][0]['id'] => 1],
    ))->toThrow(PaymentException::class);

    expect(PaymentRefund::query()->count())->toBe(0);
});

it('will not let one seller cancel another seller order', function (): void {
    $fixture = cancelFixture(quantity: 2);
    cancelGatewayAgrees();

    expect(fn () => app(CancelOrderLinesAction::class)->run(
        orderUuid: $fixture['order']->uuid,
        sellerOrgUuid: (string) Str::uuid(),
        quantities: [$fixture['lines'][0]['id'] => 1],
    ))->toThrow(PaymentException::class);

    // AND THE SAME ANSWER for a segment that is not a uuid — the shape guard,
    // before PostgreSQL turns it into a 500 (ADR-059).
    expect(fn () => app(CancelOrderLinesAction::class)->run(
        orderUuid: 'not-a-uuid',
        sellerOrgUuid: $fixture['seller'],
        quantities: [],
    ))->toThrow(PaymentException::class);

    expect(PaymentRefund::query()->count())->toBe(0);
});

it('cannot cancel more of a line than is left', function (): void {
    $fixture = cancelFixture(quantity: 2);
    $lineId = $fixture['lines'][0]['id'];
    cancelGatewayAgrees();

    app(CancelOrderLinesAction::class)->run(
        orderUuid: $fixture['order']->uuid,
        sellerOrgUuid: $fixture['seller'],
        quantities: [$lineId => 1],
    );

    /*
     * THE REMAINING-QUANTITY CHECK, REUSED UNCHANGED (S4's `RefundableLines`).
     * C1 added a trigger and a gate; it did not get its own arithmetic, which is
     * how the two paths cannot disagree about a kuruş.
     */
    expect(fn () => app(CancelOrderLinesAction::class)->run(
        orderUuid: $fixture['order']->uuid,
        sellerOrgUuid: $fixture['seller'],
        quantities: [$lineId => 2],
    ))->toThrow(PaymentException::class);

    expect(PaymentRefundLine::refundedQuantityFor($lineId))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The lever that must not reach a paid order
|--------------------------------------------------------------------------
*/

it('keeps the plain cancel lever away from a paid order', function (): void {
    $fixture = cancelFixture(quantity: 2);
    $order = $fixture['order']->fresh();

    /*
     * **THE HAZARD ADR-065 CREATED, PINNED.** Opening `Paid → Cancelled` so a
     * cancellation could name its outcome honestly made this action reachable on
     * a paid order — and it releases a hold that was already committed and ZEROES
     * the seller's stock, with the buyer's money untouched. It refuses on
     * `isCancellableWithoutRefund()` now, not on the transition table.
     */
    expect(fn () => app(CancelOrderAction::class)->run($order, new CancelOrderDTO(
        cancelledBy: CancelOrderDTO::BY_SELLER,
        reason: 'Deneme',
    )))->toThrow(OrderException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);

    // And the transition itself is still legal — reached only through the refund.
    expect(OrderStatus::Paid->canTransitionTo(OrderStatus::Cancelled))->toBeTrue()
        ->and(OrderStatus::Paid->isCancellableWithoutRefund())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| What the panel asks before it draws a form
|--------------------------------------------------------------------------
*/

it('tells the panel what is still cancellable, and nothing once it ships', function (): void {
    $fixture = cancelFixture(quantity: 2);
    $lineId = $fixture['lines'][0]['id'];
    $port = app(OrderCancellationContract::class);
    cancelGatewayAgrees();

    expect($port->cancellableQuantities($fixture['order']->uuid, $fixture['seller']))
        ->toBe([$lineId => 2]);

    $port->cancelLinesBySeller(
        orderUuid: $fixture['order']->uuid,
        sellerOrgUuid: $fixture['seller'],
        quantities: [$lineId => 1],
        reason: 'Bir tanesi kırık çıktı',
    );

    // The subtraction only Payment can do — the order still says two.
    expect($port->cancellableQuantities($fixture['order']->uuid, $fixture['seller']))
        ->toBe([$lineId => 1]);

    Shipment::query()->where('order_uuid', $fixture['order']->uuid)->update([
        'status' => ShipmentStatus::Shipped,
        'shipped_at' => now(),
    ]);

    // Nothing to draw a form from, so the button hides itself rather than opening
    // onto something that can only fail.
    expect($port->cancellableQuantities($fixture['order']->uuid, $fixture['seller']))->toBe([])
        ->and($port->cancellableQuantities($fixture['order']->uuid, (string) Str::uuid()))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The ledger, on the other side of it
|--------------------------------------------------------------------------
*/

it('reverses the sale and the commission, both, for a cancellation', function (): void {
    $fixture = cancelFixture(quantity: 2);
    $seller = $fixture['seller'];
    cancelGatewayAgrees();

    app(CancelOrderLinesAction::class)->run(
        orderUuid: $fixture['order']->uuid,
        sellerOrgUuid: $seller,
        quantities: [$fixture['lines'][0]['id'] => 2],
    );

    $entries = SellerLedgerEntry::query()->forSeller($seller)->orderBy('id')->get();

    /*
     * BOTH ENTRIES, exactly as a return makes them. A cancellation the platform
     * kept its cut on would be the seller paying a commission on goods nobody
     * received — and the append-only ledger says what happened rather than
     * editing the sale away.
     */
    expect($entries)->toHaveCount(4)
        ->and($entries[2]->type)->toBe(LedgerEntryType::RefundDebit)
        ->and($entries[2]->amount_minor)->toBe(-24_000)
        ->and($entries[3]->type)->toBe(LedgerEntryType::RefundCommissionCredit)
        ->and($entries[3]->amount_minor)->toBe(4_320)
        ->and(SellerLedgerEntry::query()->ofType(LedgerEntryType::SaleCredit)->count())->toBe(1);
});
