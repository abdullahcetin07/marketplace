<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\ApproveCancellationAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Application\Actions\RejectCancellationAction;
use App\Modules\Order\Application\Actions\RequestOrderCancellationAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Enums\CancellationRequestStatus;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\CancellationRequest;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Buyer cancel-request → seller approve/reject (ADR-065, C2)
|--------------------------------------------------------------------------
|
| **THE BUYER DOES NOT CANCEL. THEY ASK.** That is the whole feature, and it is a
| ruling about people rather than about software: a paid order may already be
| picked and packed, and the party not doing that work does not get to undo it
| alone. So the buyer's button writes a `pending` row and nothing else moves.
|
|   THE GATE       the parcel must still be awaiting handover — the SAME Core
|                  Shipping read C1's seller cancel uses, asked the same way
|   ONE OPEN       a second ask while one waits is a refusal, not a silent no-op
|   REJECT ≠ DOOR  a rejected request does not block asking again; only `pending`
|                  is unique per order
|   APPROVE        full-order refund through C1's port — no second money path —
|                  then the order is `cancelled` and the parcel `cancelled`
|   ORDER OF OPS   the refund goes FIRST and the row is stamped after, because
|                  the other way round an `approved` request can exist beside
|                  money that never moved
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A paid order with a `pending` parcel, owned by `$customer`.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{order: Order, payment: Payment, seller: string, variant: string}
 */
function requestFixture(User $customer, int $quantity = 2, int $priceMinor = 12_000): array
{
    $customerId = (int) $customer->getKey();
    $customerUuid = $customer->uuid;

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
        'order' => $order->fresh(),
        'payment' => $payment->fresh(),
        'seller' => $organization->uuid,
        'variant' => $variant->uuid,
    ];
}

function requestGatewayAgrees(): void
{
    Http::fake(['*' => Http::response(['status' => 'success', 'islem_id' => 'IPTAL-C2'])]);
}

/*
|--------------------------------------------------------------------------
| The buyer asks
|--------------------------------------------------------------------------
*/

it('records a request without touching the order', function (): void {
    $customer = Customer::factory()->create();
    $fixture = requestFixture($customer);

    $request = app(RequestOrderCancellationAction::class)->run(
        $fixture['order'],
        (int) $customer->getKey(),
        'Yanlış bedeni seçmişim',
    );

    expect($request->status)->toBe(CancellationRequestStatus::Pending)
        ->and($request->reason)->toBe('Yanlış bedeni seçmişim')
        /*
         * NOTHING ELSE MOVED, which is the ruling made visible: the order is
         * still paid, the parcel is still the seller's to send, and no money has
         * gone anywhere. A buyer who could cancel outright would be undoing a
         * sale somebody may be halfway through packing.
         */
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(PaymentRefund::query()->count())->toBe(0)
        ->and(Shipment::query()->where('order_uuid', $fixture['order']->uuid)->value('status'))
        ->toBe(ShipmentStatus::Pending);
});

it('refuses a second request while one is waiting', function (): void {
    $customer = Customer::factory()->create();
    $fixture = requestFixture($customer);

    app(RequestOrderCancellationAction::class)->run($fixture['order'], (int) $customer->getKey());

    /*
     * A REFUSAL, NOT A SILENT NO-OP RETURNING THE OPEN ROW. The two are
     * indistinguishable to the buyer and only one is true: their request is
     * already in front of the seller. Silence would read as "sent again".
     */
    expect(fn () => app(RequestOrderCancellationAction::class)
        ->run($fixture['order'], (int) $customer->getKey()))
        ->toThrow(OrderException::class);

    expect(CancellationRequest::query()->forOrder($fixture['order']->uuid)->count())->toBe(1);
});

it('lets the buyer ask again after a refusal', function (): void {
    $customer = Customer::factory()->create();
    $fixture = requestFixture($customer);

    $first = app(RequestOrderCancellationAction::class)->run($fixture['order'], (int) $customer->getKey());

    app(RejectCancellationAction::class)->run($first, 1, 'Bugün kargoya veriyoruz');

    /*
     * REJECTING DOES NOT CLOSE THE DOOR. The unique rule counts only `pending`
     * rows, deliberately: the seller's answer was about this week's stock, not
     * about the buyer's right to ask while the item still has not shipped.
     */
    $second = app(RequestOrderCancellationAction::class)->run($fixture['order'], (int) $customer->getKey());

    expect($second->status)->toBe(CancellationRequestStatus::Pending)
        ->and($first->fresh()->status)->toBe(CancellationRequestStatus::Rejected)
        ->and($first->fresh()->decision_reason)->toBe('Bugün kargoya veriyoruz')
        ->and(CancellationRequest::query()->forOrder($fixture['order']->uuid)->count())->toBe(2);
});

it('refuses to ask once the parcel is with a carrier', function (): void {
    $customer = Customer::factory()->create();
    $fixture = requestFixture($customer);

    Shipment::query()->where('order_uuid', $fixture['order']->uuid)->update([
        'status' => ShipmentStatus::Shipped,
        'shipped_at' => now(),
    ]);

    /*
     * THE SAME GATE THE SELLER'S OWN CANCEL USES, asked through the same Core
     * port. Once it has shipped the buyer's route is the RETURN (ADR-064) — a
     * different operation, with a parcel that has to physically come back.
     */
    expect(fn () => app(RequestOrderCancellationAction::class)
        ->run($fixture['order']->fresh(), (int) $customer->getKey()))
        ->toThrow(OrderException::class);

    expect(CancellationRequest::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The seller answers
|--------------------------------------------------------------------------
*/

it('refunds the whole order, restocks it and closes the parcel on approval', function (): void {
    $customer = Customer::factory()->create();
    $fixture = requestFixture($customer, quantity: 2);
    requestGatewayAgrees();

    $request = app(RequestOrderCancellationAction::class)->run(
        $fixture['order'],
        (int) $customer->getKey(),
        'Vazgeçtim',
    );

    app(ApproveCancellationAction::class)->run($request, $fixture['seller'], 1);

    $order = $fixture['order']->fresh();
    $shipment = Shipment::query()->where('order_uuid', $order->uuid)->firstOrFail();

    expect($request->fresh()->status)->toBe(CancellationRequestStatus::Approved)
        ->and($request->fresh()->decided_at)->not->toBeNull()
        // `Cancelled`, not `Refunded` — the goods never left the seller.
        ->and($order->status)->toBe(OrderStatus::Cancelled)
        // THE BUYER'S OWN WORDS travelled with the money, so the order screen
        // shows them back rather than a blank reason.
        ->and($order->cancellation_reason)->toBe('Vazgeçtim')
        ->and($shipment->status)->toBe(ShipmentStatus::Cancelled)
        ->and($fixture['payment']->fresh()->status)->toBe(PaymentStatus::Refunded)
        // The money is C1's, unchanged: 24 000 back, commission reversed, nil.
        ->and(SellerLedgerEntry::balanceFor($fixture['seller']))->toBe(0);

    $item = StockItem::query()
        ->where('selling_org_uuid', $fixture['seller'])
        ->where('variant_uuid', $fixture['variant'])
        ->firstOrFail();

    expect($item->on_hand)->toBe(20)
        ->and(PaymentRefund::query()->count())->toBe(1);
});

it('leaves the order alone when the seller says no', function (): void {
    $customer = Customer::factory()->create();
    $fixture = requestFixture($customer);

    $request = app(RequestOrderCancellationAction::class)->run($fixture['order'], (int) $customer->getKey());

    app(RejectCancellationAction::class)->run($request, 1, 'Ürün hazırlandı, bugün çıkıyor');

    expect($request->fresh()->status)->toBe(CancellationRequestStatus::Rejected)
        // THE SALE PROCEEDS EXACTLY AS IT WAS — no money, no stock, no status.
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(PaymentRefund::query()->count())->toBe(0)
        ->and(Shipment::query()->where('order_uuid', $fixture['order']->uuid)->value('status'))
        ->toBe(ShipmentStatus::Pending);
});

it('refuses to answer a request twice, either way', function (): void {
    $customer = Customer::factory()->create();
    $fixture = requestFixture($customer);
    requestGatewayAgrees();

    $request = app(RequestOrderCancellationAction::class)->run($fixture['order'], (int) $customer->getKey());

    app(RejectCancellationAction::class)->run($request, 1, 'Hayır');

    /*
     * APPROVING A REJECTED REQUEST would refund an order the seller decided to
     * fulfil; rejecting an approved one would claim a sale proceeds when its
     * money has gone back. Both are refusals rather than no-ops.
     */
    expect(fn () => app(ApproveCancellationAction::class)->run($request->fresh(), $fixture['seller'], 1))
        ->toThrow(OrderException::class);

    expect(fn () => app(RejectCancellationAction::class)->run($request->fresh(), 1, 'Yine hayır'))
        ->toThrow(OrderException::class);

    expect(PaymentRefund::query()->count())->toBe(0);
});

it('cannot be approved once the parcel left while the request sat', function (): void {
    $customer = Customer::factory()->create();
    $fixture = requestFixture($customer);
    requestGatewayAgrees();

    $request = app(RequestOrderCancellationAction::class)->run($fixture['order'], (int) $customer->getKey());

    Shipment::query()->where('order_uuid', $fixture['order']->uuid)->update([
        'status' => ShipmentStatus::Shipped,
        'shipped_at' => now(),
    ]);

    /*
     * THE GATE IS RE-ASKED AT APPROVAL, not just when the buyer asked. A request
     * can sit for days, and the seller may have shipped the box meanwhile — the
     * port answers "nothing cancellable", and the approval refuses out of the
     * same method the buyer's own attempt would have.
     */
    expect(fn () => app(ApproveCancellationAction::class)->run($request, $fixture['seller'], 1))
        ->toThrow(OrderException::class);

    expect($request->fresh()->status)->toBe(CancellationRequestStatus::Pending)
        ->and(PaymentRefund::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The storefront API
|--------------------------------------------------------------------------
*/

it('takes the request over HTTP and reports its status', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = requestFixture($customer);

    // Nobody has asked yet: there is no resource, so 404 rather than an empty one.
    $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/cancellation-request")
        ->assertNotFound();

    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/cancellation-request", [
        'reason' => 'Adresi yanlış girdim',
    ])
        ->assertCreated()
        /*
         * 201 IS NOT "CANCELLED". The resource leads with its status precisely
         * so a storefront says "satıcı onayında" instead of confirming something
         * that has not happened — ADR-065 names that as this feature's cost.
         */
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.reason', 'Adresi yanlış girdim')
        ->assertJsonPath('data.order_id', $fixture['order']->uuid);

    $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/cancellation-request")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        // The seller's employee id is NOT on a public surface (non-negotiable #7).
        ->assertJsonMissingPath('data.decided_by');

    // A second ask while one waits is a conflict, not a duplicate row.
    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/cancellation-request")
        ->assertStatus(409);
});

it('answers a stranger and an unknown order the same way', function (): void {
    $this->actingAsCustomer();
    $fixture = requestFixture(Customer::factory()->create());

    // Somebody else's order.
    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/cancellation-request")->assertNotFound();
    $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/cancellation-request")->assertNotFound();

    // AND A SEGMENT THAT IS NOT A UUID — 404, never the SQLSTATE[22P02] 500 a
    // native uuid column produces. The trap, thirteenth watch.
    $this->postJson('/api/v1/orders/not-a-uuid/cancellation-request')->assertNotFound();
    $this->getJson('/api/v1/orders/'.Str::uuid()->toString().'/cancellation-request')->assertNotFound();

    expect(CancellationRequest::query()->count())->toBe(0);
});

it('keeps the seller out of the buyer endpoint', function (): void {
    $customer = Customer::factory()->create();
    $fixture = requestFixture($customer);

    $this->actingAs(App\Models\Seller::factory()->create(), 'seller');

    /*
     * THE TWO HALVES OF AN ARGUMENT DO NOT SHARE A SURFACE. A seller answers in
     * the panel; a surface serving both is one where either can act as the other.
     */
    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/cancellation-request")
        ->assertForbidden();

    expect(CancellationRequest::query()->count())->toBe(0);
});
