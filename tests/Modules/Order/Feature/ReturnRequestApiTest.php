<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\ApproveReturnAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\ReturnRequest;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use App\Modules\Payment\Application\Listeners\OpenSettlementWindows;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| R4 — the buyer's return API (ADR-073)
|--------------------------------------------------------------------------
|
| **THE 201 MEANS "TALEP ALINDI", NOT "İADE EDİLDİ".** That is the single most
| important thing about this endpoint and the easiest thing for a storefront to
| get wrong, because the route it replaced returned 201 and meant the opposite:
| under ADR-064 the buyer's POST refunded on the spot. Every test below that
| asserts `PaymentRefund::count() === 0` after a 201 is guarding that sentence.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A paid, delivered order belonging to `$customer`, with the window open.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * `User` RATHER THAN `Customer` on the parameter, because `actingAsCustomer()` is
 * typed to the base class — the same widening every fixture in this suite makes.
 *
 * @return array{order: Order, seller: string, lines: array<int, array<string, mixed>>}
 */
function apiReturnableOrder(User $customer, int $quantity = 2): array
{
    $customerId = (int) $customer->getKey();

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

    app(AddCartItemAction::class)->run($customerId, $customer->uuid, new AddCartItemDTO(
        offerUuid: $offer->uuid,
        quantity: $quantity,
    ));

    $address = app(CreateCustomerAddressAction::class)->run($customerId, $customer->uuid, new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
    ));

    $orders = app(CheckoutAction::class)->run($customerId, $customer->uuid, new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    $group = $orders[0]->checkout_group_uuid;
    app(PlaceOrderAction::class)->run($group);

    $total = (int) Order::query()->where('checkout_group_uuid', $group)->sum('grand_total_minor');

    $payment = Payment::factory()->create([
        'checkout_group_uuid' => $group,
        'customer_id' => $customerId,
        'customer_uuid' => $customer->uuid,
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
| The write — 201, and no money
|--------------------------------------------------------------------------
*/

it('takes a return request over HTTP and refunds NOTHING', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = apiReturnableOrder($customer);

    $response = $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/return-request", [
        'lines' => [['id' => $fixture['lines'][0]['id'], 'quantity' => 1]],
        'reason' => 'Beğenmedim',
    ]);

    $response->assertCreated()
        /*
         * **THE FIELD THE STOREFRONT MUST READ.** 201 used to mean the money was
         * on its way; now it means a seller has been asked. A client that renders
         * "iade edildi" off the status code alone is the bug this payload is
         * shaped to prevent.
         */
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonPath('data.lines.0.quantity', 1)
        ->assertJsonPath('data.return_code', null)
        ->assertJsonPath('data.completed_at', null);

    expect(PaymentRefund::query()->count())->toBe(0)
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Delivered);
});

it('refuses a quantity beyond what is returnable, with a 4xx rather than a clamp', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = apiReturnableOrder($customer, quantity: 2);

    $response = $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/return-request", [
        'lines' => [['id' => $fixture['lines'][0]['id'], 'quantity' => 3]],
    ]);

    // Three of two. The action refuses; nothing is written.
    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and(ReturnRequest::query()->count())->toBe(0);
});

it('validates the shape before the query — a slug never reaches a uuid column', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = apiReturnableOrder($customer);

    /*
     * ADR-059, FIFTEENTH WATCH. `order_lines.uuid` is a native uuid column on
     * PostgreSQL, so a slug arriving in `lines.*.id` would be SQLSTATE[22P02] —
     * a 500 on a form the customer submits — rather than a refusal.
     */
    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/return-request", [
        'lines' => [['id' => 'kirmizi-ayakkabi', 'quantity' => 1]],
    ])->assertUnprocessable();

    // And an empty ask is refused rather than written as a return of nothing.
    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/return-request", [
        'lines' => [],
    ])->assertUnprocessable();
});

/*
|--------------------------------------------------------------------------
| The read — what did I ask for, and what came of it
|--------------------------------------------------------------------------
*/

it('shows the request back, with the return code once the seller approves', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = apiReturnableOrder($customer);

    // Nobody has asked yet: there is no resource, so 404 rather than an empty body.
    $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/return-request")->assertNotFound();

    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/return-request", [
        'lines' => [['id' => $fixture['lines'][0]['id'], 'quantity' => 1]],
    ])->assertCreated();

    $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/return-request")
        ->assertOk()
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonPath('data.return_code', null);

    // The seller answers, with a carrier.
    $carrier = CargoCompany::query()->firstOrFail();

    app(ApproveReturnAction::class)->run(
        ReturnRequest::query()->firstOrFail(),
        'IADE-4242',
        $carrier->uuid,
        7,
    );

    $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/return-request")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        /*
         * **THE TWO FIELDS THIS WHOLE RESOURCE EXISTS FOR.** A cancellation asks
         * the buyer to do nothing; a return asks them to walk to a cargo desk,
         * and these are the instructions.
         */
        ->assertJsonPath('data.return_code', 'IADE-4242')
        // A NAME, not a uuid — the buyer reads it, and the identifier is the
        // platform's business.
        ->assertJsonPath('data.cargo.name', $carrier->name);
});

it('answers a stranger and an unknown order the same way', function (): void {
    $this->actingAsCustomer();
    $fixture = apiReturnableOrder(Customer::factory()->create());

    // Somebody else's order — 404, the same answer a nonexistent one gets, so a
    // prober cannot tell them apart.
    $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/return-request")->assertNotFound();
    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/return-request", [
        'lines' => [['id' => $fixture['lines'][0]['id'], 'quantity' => 1]],
    ])->assertNotFound();

    // AND A SEGMENT THAT IS NOT A UUID — 404, never the SQLSTATE[22P02] 500 a
    // native uuid column produces.
    $this->getJson('/api/v1/orders/not-a-uuid/return-request')->assertNotFound();
    $this->getJson('/api/v1/orders/'.Str::uuid()->toString().'/return-request')->assertNotFound();

    expect(ReturnRequest::query()->count())->toBe(0);
});

it('no longer exposes the instant-refund POST the ADR removed', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = apiReturnableOrder($customer);

    /*
     * **THE REGRESSION GUARD FOR THE AMENDMENT ITSELF.** `POST /orders/{id}/return`
     * refunded on request (ADR-064). If it ever comes back — a merge, a revert, a
     * helpfully restored route — buyers get their money before sellers get their
     * goods, silently, and nothing else in the suite would notice.
     */
    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/return", [
        'lines' => [['id' => $fixture['lines'][0]['id'], 'quantity' => 1]],
    ])->assertStatus(405);

    // The READ survives — it is what the request form is built from.
    $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/return")->assertOk();

    expect(PaymentRefund::query()->count())->toBe(0);
});
