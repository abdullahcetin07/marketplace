<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Order\Application\Listeners\SendOrderConfirmation;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Infrastructure\Notifications\OrderConfirmationNotification;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| The receipt the buyer never used to get (§14)
|--------------------------------------------------------------------------
|
| A shopper paid and the platform said nothing: no order number, no list, no
| total. The first e-mail about a purchase was the review invitation, days later,
| after the parcel had already arrived.
|
| Pinned here: it fires on MONEY, not on placement; one e-mail covers the whole
| basket however many sellers it split across; every figure is the frozen one;
| and it can never cost a payment.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * The payment callback's success event, as the platform consumes it.
 *
 * @param array<int, string> $orderUuids
 */
function paymentSucceeded(string $checkoutGroupUuid, array $orderUuids): object
{
    return new class($checkoutGroupUuid, $orderUuids)
    {
        public string $paymentUuid = 'payment-uuid';

        /** @param array<int, string> $orderUuids */
        public function __construct(
            public string $checkoutGroupUuid,
            public array $orderUuids,
        ) {}
    };
}

/**
 * A sellable offer with stock behind it. Named for this file: Pest shares ONE
 * global function namespace across the suite.
 */
function confirmableOffer(int $priceMinor = 12_000, int $stock = 10, ?string $title = null): App\Modules\Offer\Domain\Models\Offer
{
    $organization = App\Modules\Organization\Domain\Models\Organization::factory()->create();
    $store = App\Modules\Store\Domain\Models\Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => App\Modules\Store\Domain\Enums\StoreStatus::Active,
    ]);

    $category = App\Modules\Catalog\Domain\Models\Category::factory()
        ->childOf(App\Modules\Catalog\Domain\Models\Category::factory()->create())->create();
    $product = App\Modules\Catalog\Domain\Models\Product::factory()->for($category, 'category')->published()->create([
        'title_tr' => $title ?? 'Pamuklu Tişört',
        'tax_rate_id' => App\Modules\Catalog\Domain\Models\TaxRate::factory()->rate('0.2000')->create()->getKey(),
    ]);
    $variant = App\Modules\Catalog\Domain\Models\ProductVariant::factory()->for($product)->create();

    return app(App\Modules\Offer\Application\Actions\CreateOfferAction::class)->run(
        new App\Modules\Offer\Domain\DTOs\CreateOfferDTO(
            variantUuid: $variant->uuid,
            sellingOrgId: $organization->getKey(),
            sellingOrgUuid: $organization->uuid,
            storeUuid: $store->uuid,
            priceMinor: $priceMinor,
            stockQuantity: $stock,
        ),
    );
}

/**
 * Fill a basket for this customer, check out and place. Returns the group.
 *
 * @param array<int, array{0: App\Modules\Offer\Domain\Models\Offer, 1: int}> $lines
 */
function confirmableCheckout(Customer $customer, array $lines): string
{
    $customerId = (int) $customer->getKey();

    $address = app(App\Modules\Order\Application\Actions\CreateCustomerAddressAction::class)->run(
        $customerId,
        $customer->uuid,
        new App\Modules\Order\Domain\DTOs\CustomerAddressDTO(
            label: 'Ev',
            recipientName: 'Ayşe Yılmaz',
            phone: '+905551234567',
            line1: 'Bağdat Caddesi 120',
            city: 'İstanbul',
            countryCode: 'TR',
            district: 'Kadıköy',
        ),
    );

    foreach ($lines as [$offer, $quantity]) {
        app(App\Modules\Order\Application\Actions\AddCartItemAction::class)->run(
            $customerId,
            $customer->uuid,
            new App\Modules\Order\Domain\DTOs\AddCartItemDTO(offerUuid: $offer->uuid, quantity: $quantity),
        );
    }

    $orders = app(App\Modules\Order\Application\Actions\CheckoutAction::class)->run(
        $customerId,
        $customer->uuid,
        new App\Modules\Order\Domain\DTOs\CheckoutDTO(
            shippingAddressUuid: $address->uuid,
            billingAddressUuid: $address->uuid,
        ),
    );

    $group = (string) $orders[0]->checkout_group_uuid;

    app(App\Modules\Order\Application\Actions\PlaceOrderAction::class)->run($group);

    return $group;
}

it('sends one confirmation for a basket, whatever it split into', function (): void {
    Notification::fake();

    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    $first = confirmableOffer(title: 'Pamuklu Tişört');
    $second = confirmableOffer(title: 'Deri Ayakkabı');
    $group = confirmableCheckout($customer, [[$first, 2], [$second, 1]]);

    $orders = Order::query()->where('checkout_group_uuid', $group)->orderBy('id')->get();

    expect($orders)->toHaveCount(2);

    app(SendOrderConfirmation::class)->handle(
        paymentSucceeded($group, $orders->pluck('uuid')->all()),
    );

    // TWO sellers, ONE e-mail: three arriving in a minute reads as a system
    // malfunctioning, so the split is shown rather than sent.
    Notification::assertSentToTimes($customer, OrderConfirmationNotification::class, 1);
});

it('names every seller order and totals what the card was charged', function (): void {
    Notification::fake();

    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    $offer = confirmableOffer(priceMinor: 12_000, title: 'Pamuklu Tişört');
    $group = confirmableCheckout($customer, [[$offer, 2]]);

    $order = Order::query()->where('checkout_group_uuid', $group)->sole();

    app(SendOrderConfirmation::class)->handle(paymentSucceeded($group, [$order->uuid]));

    Notification::assertSentTo($customer, OrderConfirmationNotification::class,
        function (OrderConfirmationNotification $notification) use ($customer, $order): bool {
            $mail = $notification->toMail($customer);
            $body = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

            return str_contains($body, (string) $order->order_number)
                && str_contains($body, 'Pamuklu Tişört')
                // The frozen figure, not today's price (ADR-053).
                && str_contains($body, money((int) $order->grand_total_minor, $order->currency))
                // The address the parcel is actually going to.
                && str_contains($body, 'Ayşe Yılmaz');
        });
});

it('says nothing when there is nobody left to write to', function (): void {
    Notification::fake();

    /** @var Customer $customer */
    $customer = Customer::factory()->create();
    $offer = confirmableOffer();
    $group = confirmableCheckout($customer, [[$offer, 1]]);

    $order = Order::query()->where('checkout_group_uuid', $group)->sole();

    // The account went away between the charge and the callback.
    $customer->forceDelete();

    app(SendOrderConfirmation::class)->handle(paymentSucceeded($group, [$order->uuid]));

    Notification::assertNothingSent();
});

it('never costs a payment when the receipt cannot be built', function (): void {
    Notification::fake();

    /*
     * It runs inside PayTR's callback, beside the listeners that confirm orders
     * and open shipments. A receipt is worth less than the sale, so a broken one
     * is reported and swallowed rather than thrown.
     */
    app(SendOrderConfirmation::class)->handle(paymentSucceeded('bilinmeyen-grup', ['yok']));
    app(SendOrderConfirmation::class)->handle(paymentSucceeded('bos', []));

    Notification::assertNothingSent();
});
