<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockReservation;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Payment\Application\Actions\CreatePayoutAction;
use App\Modules\Payment\Application\Actions\RefundLinesAction;
use App\Modules\Payment\Application\Actions\RequestReturnAction;
use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use App\Modules\Payment\Application\Listeners\OpenSettlementWindows;
use App\Modules\Payment\Domain\DTOs\ReturnRequestDTO;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\PaymentRefundLine;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Payment\Domain\Models\SettlementWindow;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Buyer returns and line-level partial refunds (S4, Payment.md §8)
|--------------------------------------------------------------------------
|
| **P5 REFUNDED WHOLE ORDERS. THIS REFUNDS ITEMS**, and every hard case in the
| file comes from that one change of granularity:
|
|   PROPORTIONAL   one of two units gets back one unit's KDV-inclusive price and
|                  one unit's share of the frozen commission — to the kuruş
|   LAST UNIT      whatever rounding stranded on a line goes back with its final
|                  unit, so a fully returned line leaves nothing behind
|   REMAINING      a line may go back up to what has not already gone back; that
|                  arithmetic REPLACED a unique index, so it is tested where the
|                  index used to be
|   TWICE IS LEGAL a second refund of one order is now correct, which is exactly
|                  what P5's constraint forbade
|   PER QUANTITY   Inventory puts back the units that came back, not the hold
|   THE WINDOW     a buyer may return a DELIVERED parcel, in time, that is theirs
|   FULLY BACK     only then does the parcel become `returned` and the payment
|                  `refunded`
|
| A basket here is TWO UNITS OF ONE LINE at 12 000 kuruş, 18% commission — the
| smallest fixture in which "half of it" is a different number from "all of it".
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A paid, delivered order of `$quantity` units of one line.
 *
 * Named for this file: Pest shares ONE global function namespace across the
 * suite, so `returnFixture` may not collide with `refundFixture` next door.
 *
 * @return array{payment: Payment, order: Order, seller: string, variant: string, lines: array<int, array<string, mixed>>}
 */
function returnFixture(int $quantity = 2, int $priceMinor = 12_000, ?User $customer = null): array
{
    $customerId = $customer === null ? 1 : (int) $customer->getKey();
    $customerUuid = $customer === null ? 'musteri' : $customer->uuid;

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

    app(AddCartItemAction::class)->run($customerId, $customerUuid, new AddCartItemDTO(
        offerUuid: $offer->uuid,
        quantity: $quantity,
    ));

    $address = app(CreateCustomerAddressAction::class)->run($customerId, $customerUuid, new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
    ));

    $orders = app(CheckoutAction::class)->run($customerId, $customerUuid, new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    $group = $orders[0]->checkout_group_uuid;

    app(PlaceOrderAction::class)->run($group);

    $total = (int) Order::query()->where('checkout_group_uuid', $group)->sum('grand_total_minor');

    $payment = Payment::factory()->create([
        'checkout_group_uuid' => $group,
        'customer_id' => $customerId,
        'customer_uuid' => $customerUuid,
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
        'order' => $order,
        'seller' => $organization->uuid,
        'variant' => $variant->uuid,
        'lines' => app(App\Core\Domain\Contracts\OrderQueryContract::class)->orderLines($order->uuid),
    ];
}

/**
 * The parcel arrives — the fact S3's windows and S4's button both hang off.
 *
 * IT GOES THROUGH THE LISTENER, not straight into the table, because the dates
 * being FROZEN from the event's delivery time rather than the consuming clock is
 * half of what makes the window trustworthy.
 */
function deliverParcel(string $orderUuid, string $sellerOrgUuid, ?string $deliveredAt = null): void
{
    Shipment::query()->where('order_uuid', $orderUuid)->update([
        'status' => ShipmentStatus::Delivered,
        'delivered_at' => now(),
        'delivered_via' => 'buyer',
    ]);

    app(OpenSettlementWindows::class)->handle(new class($orderUuid, $sellerOrgUuid, $deliveredAt ?? now()->toIso8601String())
    {
        public function __construct(
            public string $orderUuid,
            public string $sellerOrgUuid,
            public string $deliveredAt,
            public string $deliveredVia = 'buyer',
        ) {}
    });
}

/**
 * PayTR agreeing to send the money back.
 */
function returnGatewayAgrees(): void
{
    Http::fake(['*' => Http::response(['status' => 'success', 'islem_id' => 'IADE-S4'])]);
}

/**
 * An administrator who may refund. Signing them in is the caller's job — Pest's
 * `test()` proxy is not typed for it.
 */
function returnAdmin(): Admin
{
    /** @var Admin $admin */
    $admin = Admin::factory()->create();
    $admin->assignRole(config('marketplace.roles.super_admin'));

    return $admin;
}

/*
|--------------------------------------------------------------------------
| The arithmetic
|--------------------------------------------------------------------------
*/

it('refunds one of two units to the kuruş, with its share of the commission', function (): void {
    $fixture = returnFixture(quantity: 2);
    $seller = $fixture['seller'];
    $line = $fixture['lines'][0];

    // 24 000 sold, 18% taken: the seller is owed 19 680 before anything comes back.
    expect(SellerLedgerEntry::balanceFor($seller))->toBe(19_680)
        ->and($line['quantity'])->toBe(2)
        ->and($line['line_total_minor'])->toBe(24_000)
        ->and($line['commission_minor'])->toBe(4_320);

    returnGatewayAgrees();

    $refund = app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$line['id'] => 1],
    ));

    /*
     * ONE UNIT'S KDV-INCLUSIVE PRICE, and no separate tax term. Turkish retail
     * prices INCLUDE the KDV (ADR-055), so refunding the price refunds the tax
     * with it, in exactly the proportion it was charged. Adding a "proportional
     * KDV" line on top would give the buyer the tax twice.
     */
    expect($refund->amount_minor)->toBe(12_000);

    $refundLine = PaymentRefundLine::query()->firstOrFail();

    expect($refundLine->quantity)->toBe(1)
        ->and($refundLine->amount_minor)->toBe(12_000)
        // HALF THE FROZEN FIGURE — not the rules resolved again (ADR-061), which
        // would apply today's rates to last month's sale.
        ->and($refundLine->commission_minor)->toBe(2_160);

    $entries = SellerLedgerEntry::query()->forSeller($seller)->orderBy('id')->get();

    expect($entries)->toHaveCount(4)
        ->and($entries[2]->type)->toBe(LedgerEntryType::RefundDebit)
        ->and($entries[2]->amount_minor)->toBe(-12_000)
        ->and($entries[3]->type)->toBe(LedgerEntryType::RefundCommissionCredit)
        ->and($entries[3]->amount_minor)->toBe(2_160);

    // Exactly one unit's worth of the sale undone: 19 680 − 12 000 + 2 160.
    expect(SellerLedgerEntry::balanceFor($seller))->toBe(9_840)
        // HALF A REFUND IS NOT A REFUND. The basket is partly back, and the
        // payment says so rather than claiming the sale is undone.
        ->and($fixture['payment']->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('puts back the units that came back, not the whole hold', function (): void {
    $fixture = returnFixture(quantity: 2);

    $item = StockItem::query()
        ->where('selling_org_uuid', $fixture['seller'])
        ->where('variant_uuid', $fixture['variant'])
        ->firstOrFail();

    // Two sold from twenty, and the hold ended when the sale completed.
    expect($item->on_hand)->toBe(18)
        ->and($item->reserved)->toBe(0);

    returnGatewayAgrees();

    app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$fixture['lines'][0]['id'] => 1],
    ));

    /*
     * NINETEEN, NOT TWENTY. P5's restock was all-or-nothing because a refund was;
     * restocking the whole reservation here would invent a unit the buyer still
     * has on their shelf, and the seller would sell it to somebody.
     */
    expect($item->fresh()->on_hand)->toBe(19);

    $reservation = StockReservation::query()
        ->where('reference', "{$fixture['order']->uuid}:{$fixture['variant']}")
        ->firstOrFail();

    // STILL COMMITTED. Half the sale stands, so the reservation is not terminal —
    // the status flips only when the last unit is home.
    expect($reservation->restocked_quantity)->toBe(1)
        ->and($reservation->status)->toBe(ReservationStatus::Committed);
});

/*
|--------------------------------------------------------------------------
| The guard that replaced the unique index
|--------------------------------------------------------------------------
*/

it('refuses to send back more than the buyer ever had', function (): void {
    $fixture = returnFixture(quantity: 2);
    returnGatewayAgrees();

    /*
     * THREE OF TWO IS A REFUSAL, NOT A CLAMP. A caller that miscounted should
     * hear about it rather than be quietly given a different answer than it
     * asked for — the money involved is somebody's.
     */
    expect(fn () => app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$fixture['lines'][0]['id'] => 3],
    )))->toThrow(PaymentException::class);

    expect(PaymentRefund::query()->count())->toBe(0)
        // AND NOTHING REACHED THE PSP. The guard runs before the gateway does.
        ->and(SellerLedgerEntry::query()->ofType(LedgerEntryType::RefundDebit)->count())->toBe(0);
});

it('refuses a second return of a unit already sent back', function (): void {
    $fixture = returnFixture(quantity: 2);
    $lineId = $fixture['lines'][0]['id'];
    returnGatewayAgrees();

    app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$lineId => 2],
    ));

    /*
     * THE CHECK THAT USED TO BE A CONSTRAINT. P5 held this with a unique index on
     * `(payment, order)`; S4 had to drop it so a second partial refund could
     * happen at all, and this is the arithmetic that took its place.
     */
    expect(fn () => app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$lineId => 1],
    )))->toThrow(PaymentException::class);

    expect(PaymentRefundLine::refundedQuantityFor($lineId))->toBe(2)
        ->and(PaymentRefund::query()->count())->toBe(1);
});

it('lets one order be refunded twice, which the old index forbade', function (): void {
    $fixture = returnFixture(quantity: 2);
    $lineId = $fixture['lines'][0]['id'];
    $seller = $fixture['seller'];
    returnGatewayAgrees();

    app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$lineId => 1],
    ));

    // Next week, the other shoe.
    $second = app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$lineId => 1],
    ));

    /*
     * THE LAST UNIT TAKES THE REMAINDER: it is billed as "everything not yet
     * refunded" rather than as `unit_price × 1`, so a rounding upstream cannot
     * strand a kuruş on a fully returned line forever.
     */
    expect($second->amount_minor)->toBe(12_000)
        ->and(PaymentRefund::query()->count())->toBe(2)
        // Two refunds, TWO PAIRS of ledger entries — the settlement unique index
        // had to go with the refund one, and this is what it was blocking.
        ->and(SellerLedgerEntry::query()->ofType(LedgerEntryType::RefundDebit)->count())->toBe(2)
        ->and(SellerLedgerEntry::balanceFor($seller))->toBe(0)
        // WHOLLY BACK NOW, so the payment stops being "partially".
        ->and($fixture['payment']->fresh()->status)->toBe(PaymentStatus::Refunded);

    $reservation = StockReservation::query()
        ->where('reference', "{$fixture['order']->uuid}:{$fixture['variant']}")
        ->firstOrFail();

    expect($reservation->restocked_quantity)->toBe(2)
        ->and($reservation->status)->toBe(ReservationStatus::Restocked);
});

/*
|--------------------------------------------------------------------------
| The parcel
|--------------------------------------------------------------------------
*/

it('marks the parcel returned only once every unit is back', function (): void {
    $fixture = returnFixture(quantity: 2);
    $lineId = $fixture['lines'][0]['id'];
    deliverParcel($fixture['order']->uuid, $fixture['seller']);
    returnGatewayAgrees();

    app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$lineId => 1],
    ));

    /*
     * STILL DELIVERED. A buyer who kept one of two shoes has a parcel that
     * arrived; calling it `returned` would tell every screen the whole thing came
     * back.
     */
    expect(Shipment::query()->where('order_uuid', $fixture['order']->uuid)->value('status'))
        ->toBe(ShipmentStatus::Delivered);

    app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$lineId => 1],
    ));

    $shipment = Shipment::query()->where('order_uuid', $fixture['order']->uuid)->firstOrFail();

    expect($shipment->status)->toBe(ShipmentStatus::Returned)
        ->and($shipment->returned_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The buyer's own button
|--------------------------------------------------------------------------
*/

it('lets the buyer send it back inside the window', function (): void {
    $customer = Customer::factory()->create();
    $fixture = returnFixture(quantity: 2, customer: $customer);
    deliverParcel($fixture['order']->uuid, $fixture['seller']);
    returnGatewayAgrees();

    $refund = app(RequestReturnAction::class)->run(
        new ReturnRequestDTO(
            orderUuid: $fixture['order']->uuid,
            quantities: [$fixture['lines'][0]['id'] => 1],
            reason: 'Numara küçük geldi',
        ),
        (int) $customer->getKey(),
    );

    expect($refund->amount_minor)->toBe(12_000)
        ->and($refund->reason)->toBe('Numara küçük geldi');
});

it('refuses a return of a parcel that never arrived', function (): void {
    $customer = Customer::factory()->create();
    $fixture = returnFixture(quantity: 2, customer: $customer);
    returnGatewayAgrees();

    /*
     * NO WINDOW MEANS NOT DELIVERED — only a delivery opens one (S3). A buyer
     * whose parcel is still in transit is asking to CANCEL, which has different
     * consequences for the seller's stock and is a different button.
     */
    expect(fn () => app(RequestReturnAction::class)->run(
        new ReturnRequestDTO(
            orderUuid: $fixture['order']->uuid,
            quantities: [$fixture['lines'][0]['id'] => 1],
        ),
        (int) $customer->getKey(),
    ))->toThrow(PaymentException::class);

    expect(PaymentRefund::query()->count())->toBe(0);
});

it('refuses a return once the window has closed', function (): void {
    $customer = Customer::factory()->create();
    $fixture = returnFixture(quantity: 2, customer: $customer);
    deliverParcel($fixture['order']->uuid, $fixture['seller']);
    returnGatewayAgrees();

    // `return_days` defaults to 14, and the window is frozen at delivery.
    $this->travel(15)->days();

    expect(fn () => app(RequestReturnAction::class)->run(
        new ReturnRequestDTO(
            orderUuid: $fixture['order']->uuid,
            quantities: [$fixture['lines'][0]['id'] => 1],
        ),
        (int) $customer->getKey(),
    ))->toThrow(PaymentException::class);

    expect(PaymentRefund::query()->count())->toBe(0);

    /*
     * AND AN ADMIN STILL CAN, which is the point of the window rather than an
     * exception to it: after it closes, a refund is a human's judgement call
     * again — the same machinery, without the buyer's guards.
     */
    app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$fixture['lines'][0]['id'] => 1],
    ));

    expect(PaymentRefund::query()->count())->toBe(1);
});

it('will not let one customer return another customer order', function (): void {
    $customer = Customer::factory()->create();
    $stranger = Customer::factory()->create();
    $fixture = returnFixture(quantity: 2, customer: $customer);
    deliverParcel($fixture['order']->uuid, $fixture['seller']);
    returnGatewayAgrees();

    expect(fn () => app(RequestReturnAction::class)->run(
        new ReturnRequestDTO(
            orderUuid: $fixture['order']->uuid,
            quantities: [$fixture['lines'][0]['id'] => 1],
        ),
        (int) $stranger->getKey(),
    ))->toThrow(PaymentException::class);

    // AND THE SAME ANSWER for an order uuid that is not even a uuid — the shape
    // guard, before PostgreSQL turns it into a 500 (ADR-059).
    expect(fn () => app(RequestReturnAction::class)->run(
        new ReturnRequestDTO(orderUuid: 'not-a-uuid', quantities: []),
        (int) $customer->getKey(),
    ))->toThrow(PaymentException::class);

    expect(PaymentRefund::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The API the storefront calls
|--------------------------------------------------------------------------
*/

it('tells the storefront what may still go back, and until when', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = returnFixture(quantity: 2, customer: $customer);
    deliverParcel($fixture['order']->uuid, $fixture['seller']);
    returnGatewayAgrees();

    app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$fixture['lines'][0]['id'] => 1],
    ));

    $response = $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/return");

    $response->assertOk()
        ->assertJsonPath('data.return_open', true)
        ->assertJsonPath('data.lines.0.quantity', 2)
        ->assertJsonPath('data.lines.0.returned_quantity', 1)
        ->assertJsonPath('data.lines.0.returnable_quantity', 1)
        /*
         * THE AMOUNT COMES FROM THE PLATFORM, and that is why the endpoint
         * exists: a storefront multiplying `unit_price × quantity` itself would
         * disagree with the last unit's remainder on exactly the orders somebody
         * bothered to check.
         */
        ->assertJsonPath('data.lines.0.refundable_amount_minor', 12_000)
        ->assertJsonPath('data.lines.0.refundable_amount', '120.00');
});

it('takes the return request from the buyer over HTTP', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = returnFixture(quantity: 2, customer: $customer);
    deliverParcel($fixture['order']->uuid, $fixture['seller']);
    returnGatewayAgrees();

    $response = $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/return", [
        'lines' => [['id' => $fixture['lines'][0]['id'], 'quantity' => 1]],
        'reason' => 'Beğenmedim',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.amount_minor', 12_000);

    expect(PaymentRefundLine::refundedQuantityFor($fixture['lines'][0]['id']))->toBe(1);
});

it('answers a stranger and an unknown order the same way', function (): void {
    $this->actingAsCustomer();
    $fixture = returnFixture(quantity: 2, customer: Customer::factory()->create());
    deliverParcel($fixture['order']->uuid, $fixture['seller']);

    // Somebody else's order.
    $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/return")->assertNotFound();

    // AND A SEGMENT THAT IS NOT A UUID — 404, never the SQLSTATE[22P02] 500 a
    // native uuid column produces. The trap, eleventh watch.
    $this->getJson('/api/v1/orders/not-a-uuid/return')->assertNotFound();
    $this->getJson('/api/v1/orders/'.Str::uuid()->toString().'/return')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The admin's line-level door
|--------------------------------------------------------------------------
*/

it('lets an admin finish off an order a buyer partly returned', function (): void {
    $fixture = returnFixture(quantity: 2);
    $lineId = $fixture['lines'][0]['id'];
    returnGatewayAgrees();

    app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$lineId => 1],
    ));

    $this->actingAs(returnAdmin(), 'admin');

    /*
     * THE HOLE S4 DUG AND THIS FILLS. The whole-order path SKIPS an order that
     * already has a refund row — correctly, or it would refund the returned shoe
     * a second time — so without a line-level admin door this order would be
     * stuck partly refunded forever.
     */
    $response = $this->postJson("/api/v1/admin/payments/{$fixture['payment']->uuid}/refund", [
        'order_id' => $fixture['order']->uuid,
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ]);

    $response->assertOk();

    expect(PaymentRefundLine::refundedQuantityFor($lineId))->toBe(2)
        ->and($fixture['payment']->fresh()->status)->toBe(PaymentStatus::Refunded);
});

it('will not refund an order through a payment it does not belong to', function (): void {
    $fixture = returnFixture(quantity: 2);
    $stranger = returnFixture(quantity: 1);

    $this->actingAs(returnAdmin(), 'admin');
    returnGatewayAgrees();

    $this->postJson("/api/v1/admin/payments/{$fixture['payment']->uuid}/refund", [
        'order_id' => $stranger['order']->uuid,
        'lines' => [['id' => $stranger['lines'][0]['id'], 'quantity' => 1]],
    ])->assertNotFound();

    expect(PaymentRefund::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| After the money has already gone out
|--------------------------------------------------------------------------
*/

it('stays honest when a partial return lands after the payout', function (): void {
    $fixture = returnFixture(quantity: 2);
    $seller = $fixture['seller'];
    deliverParcel($fixture['order']->uuid, $seller);

    /** @var Admin $admin */
    $admin = Admin::factory()->create();

    $this->travel(15)->days();

    // The platform pays the seller everything it owed — before a single unit
    // comes back.
    app(CreatePayoutAction::class)->run($seller, 19_680, $admin->getKey());

    expect(SellerLedgerEntry::balanceFor($seller))->toBe(0);

    returnGatewayAgrees();

    // An admin refunds one unit after the window closed. The buyer's door is
    // shut; this one is not.
    app(RefundLinesAction::class)->run(new ReturnRequestDTO(
        orderUuid: $fixture['order']->uuid,
        quantities: [$fixture['lines'][0]['id'] => 1],
    ));

    /*
     * NEGATIVE, AND THAT IS ALLOWED (Payment.md §8). The money left twice — once
     * to the seller, once back to the buyer — and a balance that is a SUM records
     * it rather than clamping at zero and losing track. The payout ceiling does
     * the rest: nothing more goes out until later sales make it whole.
     */
    expect(SellerLedgerEntry::balanceFor($seller))->toBe(-9_840);

    expect(fn () => app(CreatePayoutAction::class)->run($seller, 1, $admin->getKey()))
        ->toThrow(PaymentException::class);
});

it('will not touch a window that no delivery opened', function (): void {
    $fixture = returnFixture(quantity: 1);

    expect(SettlementWindow::query()->where('order_uuid', $fixture['order']->uuid)->exists())->toBeFalse();
});
