<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Application\Actions\WithdrawOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Application\Listeners\SettleOrdersOnPayment;
use App\Modules\Order\Domain\Contracts\CartRepositoryContract;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| A declined card gives the basket back (§13)
|--------------------------------------------------------------------------
|
| Checkout empties the cart, and until 2026-09-22 nothing refilled it: the
| shopper was left with no basket, an order they could only cancel, and a failure
| page promising "Sepetiniz duruyor" over an empty one. 16 customers met that on
| production; 3 bought anything afterwards.
|
| What is pinned here is the shape of the recovery, and mostly its LIMITS: the
| callback arrives more than once, a paid basket must never be handed back, and
| the shopper's own cart outranks whatever the restore wanted to write.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A sellable offer with stock behind it.
 *
 * Named for this file: Pest shares ONE global function namespace across the
 * whole suite, so a duplicate anywhere is a fatal redeclare.
 */
function restorableOffer(int $priceMinor = 12_000, int $stock = 10, ?string $title = null): App\Modules\Offer\Domain\Models\Offer
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create([
        'title_tr' => $title ?? 'Pamuklu Tişört',
        'tax_rate_id' => TaxRate::factory()->rate('0.2000')->create()->getKey(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create();

    return app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: $priceMinor,
        stockQuantity: $stock,
    ));
}

function restorableCustomer(int $customerId = 1): string
{
    app(CreateCustomerAddressAction::class)->run($customerId, 'musteri', new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
        district: 'Kadıköy',
    ));

    return 'musteri';
}

/**
 * Put the lines in the basket, check out AND place — the state a payment can
 * actually fail from. Checkout alone leaves the orders `Pending`; placement is
 * what moves them to `AwaitingPayment` (ADR-057).
 *
 * @param array<int, array{0: App\Modules\Offer\Domain\Models\Offer, 1: int}> $lines
 */
function restorableCheckout(array $lines, int $customerId = 1): string
{
    $customerUuid = restorableCustomer($customerId);

    foreach ($lines as [$offer, $quantity]) {
        app(AddCartItemAction::class)->run($customerId, $customerUuid, new AddCartItemDTO(
            offerUuid: $offer->uuid,
            quantity: $quantity,
        ));
    }

    $addresses = App\Modules\Order\Domain\Models\CustomerAddress::query()
        ->where('customer_id', $customerId)->pluck('uuid')->all();

    $orders = app(CheckoutAction::class)->run($customerId, $customerUuid, new CheckoutDTO(
        shippingAddressUuid: $addresses[0],
        billingAddressUuid: $addresses[0],
    ));

    $group = (string) $orders[0]->checkout_group_uuid;

    app(PlaceOrderAction::class)->run($group);

    return $group;
}

/**
 * The payment callback's failure event, as the platform consumes it: a plain
 * object read by property, never a typed import (Payment.md §3).
 */
function paymentFailed(string $checkoutGroupUuid): object
{
    return new class($checkoutGroupUuid)
    {
        public string $paymentUuid = 'payment-uuid';

        public string $reason = 'declined';

        public function __construct(public string $checkoutGroupUuid) {}
    };
}

/**
 * @return array<int, array{offer: string, quantity: int}>
 */
function cartContents(int $customerId = 1): array
{
    $cart = app(CartRepositoryContract::class)->forCustomer($customerId);

    if ($cart === null) {
        return [];
    }

    return $cart->items()->get()
        ->map(fn ($item): array => ['offer' => $item->offer_uuid, 'quantity' => (int) $item->quantity])
        ->all();
}

it('puts the lines back and ends the orders', function (): void {
    $offer = restorableOffer();
    $group = restorableCheckout([[$offer, 3]]);

    // Checkout emptied it — that is the behaviour this feature exists to undo.
    expect(cartContents())->toBe([]);

    app(SettleOrdersOnPayment::class)->onFailed(paymentFailed($group));

    expect(cartContents())->toBe([['offer' => $offer->uuid, 'quantity' => 3]])
        // One basket in one place: no live order beside a full cart.
        ->and(Order::query()->where('checkout_group_uuid', $group)->sole()->status)
        ->toBe(OrderStatus::Expired);
});

it('brings every seller’s lines back into the one cart', function (): void {
    $first = restorableOffer(title: 'Pamuklu Tişört');
    $second = restorableOffer(title: 'Deri Ayakkabı');

    $group = restorableCheckout([[$first, 1], [$second, 2]]);

    // Two sellers, so the checkout split into two orders (ADR-052).
    expect(Order::query()->where('checkout_group_uuid', $group)->count())->toBe(2);

    app(SettleOrdersOnPayment::class)->onFailed(paymentFailed($group));

    expect(cartContents())->toHaveCount(2)
        ->and(collect(cartContents())->pluck('quantity')->sort()->values()->all())->toBe([1, 2])
        ->and(Order::query()->where('checkout_group_uuid', $group)
            ->where('status', OrderStatus::Expired->value)->count())->toBe(2);
});

it('does not double the basket when PayTR retries its callback', function (): void {
    $offer = restorableOffer();
    $group = restorableCheckout([[$offer, 2]]);

    // The callback is retried until it hears OK, so twice is ordinary.
    app(SettleOrdersOnPayment::class)->onFailed(paymentFailed($group));
    app(SettleOrdersOnPayment::class)->onFailed(paymentFailed($group));

    expect(cartContents())->toBe([['offer' => $offer->uuid, 'quantity' => 2]]);
});

it('never hands back a basket that was paid for', function (): void {
    $offer = restorableOffer();
    $group = restorableCheckout([[$offer, 1]]);

    Order::query()->where('checkout_group_uuid', $group)
        ->update(['status' => OrderStatus::Paid->value]);

    // A late failure event on a group that settled must not resurrect the cart.
    app(SettleOrdersOnPayment::class)->onFailed(paymentFailed($group));

    expect(cartContents())->toBe([])
        ->and(Order::query()->where('checkout_group_uuid', $group)->sole()->status)
        ->toBe(OrderStatus::Paid);
});

it('skips a line the catalogue has moved on from, and returns the rest', function (): void {
    $alive = restorableOffer(title: 'Pamuklu Tişört');
    $gone = restorableOffer(title: 'Deri Ayakkabı');

    $group = restorableCheckout([[$alive, 1], [$gone, 1]]);

    // The seller pulled it while the card was being declined.
    app(WithdrawOfferAction::class)->run($gone);

    app(SettleOrdersOnPayment::class)->onFailed(paymentFailed($group));

    // Nine items back is strictly better than none: the dead line is skipped.
    expect(cartContents())->toBe([['offer' => $alive->uuid, 'quantity' => 1]]);
});

it('leaves a line the shopper already put back themselves', function (): void {
    $offer = restorableOffer();
    $group = restorableCheckout([[$offer, 3]]);

    // They gave up waiting and re-added it by hand, with a different quantity.
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO(
        offerUuid: $offer->uuid,
        quantity: 1,
    ));

    app(SettleOrdersOnPayment::class)->onFailed(paymentFailed($group));

    // Their own edit outranks the restore — never silently four.
    expect(cartContents())->toBe([['offer' => $offer->uuid, 'quantity' => 1]]);
});

it('survives a group it cannot make sense of', function (): void {
    // A malformed or unknown payload must not break the callback it rides in.
    app(SettleOrdersOnPayment::class)->onFailed(paymentFailed('not-a-group'));
    app(SettleOrdersOnPayment::class)->onFailed(paymentFailed(''));

    expect(cartContents())->toBe([]);
});

it('also hands the basket back when the shopper just walks away', function (): void {
    /*
     * THE COMMONER ENDING. Closing the tab at the payment form produces NO
     * callback — PayTR never says anything — so the only thing that learns of it
     * is the expiry sweep (ADR-072). To the shopper it looked exactly like a
     * declined card: an empty cart. Restoring only the declined one would have
     * left the promise half true.
     */
    $offer = restorableOffer();
    $group = restorableCheckout([[$offer, 2]]);

    expect(cartContents())->toBe([]);

    $this->travel(6)->minutes();

    app(App\Modules\Order\Application\Jobs\ExpireAwaitingPaymentJob::class)->handle(
        app(App\Modules\Order\Domain\Contracts\OrderRepositoryContract::class),
        app(App\Modules\Order\Application\Actions\RestoreCartFromUnpaidCheckoutAction::class),
    );

    expect(cartContents())->toBe([['offer' => $offer->uuid, 'quantity' => 2]])
        ->and(Order::query()->where('checkout_group_uuid', $group)->sole()->status)
        ->toBe(OrderStatus::Expired);
});
