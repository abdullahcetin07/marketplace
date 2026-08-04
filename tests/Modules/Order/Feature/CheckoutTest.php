<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Application\Actions\PauseOfferAction;
use App\Modules\Offer\Application\Actions\UpdateOfferPriceAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferPriceDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CancelOrderAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Application\Jobs\ExpireReservationsJob;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CancelOrderDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Events\CartCheckedOut;
use App\Modules\Order\Domain\Events\OrderCancelled;
use App\Modules\Order\Domain\Events\OrderPlaced;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\Cart;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Checkout, placement, cancellation — the core (ADR-052/053/054/055/057)
|--------------------------------------------------------------------------
|
| Four decisions meet in one transaction, and this file exercises each of them
| against the REAL other modules rather than fakes. That is deliberate: Order is
| the first real caller of Inventory's command contract (ADR-049), and a test that
| mocked the contract would prove only that Order calls a method — not that a
| seller's stock actually moved, which is the entire claim.
|
|  THE SPLIT      one basket, N sellers, N orders, one checkout group
|  THE SNAPSHOT   price/title/tax frozen; upstream changes never reach a placed line
|  THE RESERVE    checkout HOLDS, placement KEEPS HOLDING (ADR-057), cancel RELEASES
|  THE TAX        extracted per line from a KDV-included price, in integers
|
| ALL-OR-NOTHING is the property most worth pinning: a failure anywhere must leave
| no orders and no holds. Half a purchase is worse than none.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A real, sellable offer with real stock behind it.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{offer: \App\Modules\Offer\Domain\Models\Offer, org: Organization, store: Store, variant: ProductVariant, product: Product}
 */
function checkoutFixture(
    int $priceMinor = 12_000,
    int $stock = 10,
    string $taxRatio = '0.2000',
    ?string $title = null,
): array {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create([
        'title_tr' => $title ?? 'Pamuklu Tişört',
        'tax_rate_id' => TaxRate::factory()->rate($taxRatio)->create()->getKey(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create();

    $offer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: $priceMinor,
        stockQuantity: $stock,
    ));

    return compact('offer', 'organization', 'store', 'variant', 'product')
        + ['org' => $organization];
}

/**
 * A customer with an address book, ready to check out.
 *
 * @return array{shipping: string, billing: string}
 */
function checkoutAddresses(int $customerId = 1): array
{
    $home = app(CreateCustomerAddressAction::class)->run($customerId, 'musteri', new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
        district: 'Kadıköy',
    ));

    $work = app(CreateCustomerAddressAction::class)->run($customerId, 'musteri', new CustomerAddressDTO(
        label: 'İş',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905559876543',
        line1: 'Levent Plaza 7',
        city: 'İstanbul',
        countryCode: 'TR',
        district: 'Beşiktaş',
    ));

    return ['shipping' => $home->uuid, 'billing' => $work->uuid];
}

/**
 * @return array<int, Order>
 */
function checkOut(int $customerId = 1): array
{
    $addresses = checkoutAddresses($customerId);

    return app(CheckoutAction::class)->run($customerId, 'musteri', new CheckoutDTO(
        shippingAddressUuid: $addresses['shipping'],
        billingAddressUuid: $addresses['billing'],
    ));
}

/*
|--------------------------------------------------------------------------
| The split (ADR-052)
|--------------------------------------------------------------------------
*/

it('splits one basket into one order per seller, under one checkout group', function (): void {
    $first = checkoutFixture(priceMinor: 10_000, title: 'Tişört');
    $second = checkoutFixture(priceMinor: 5_000, title: 'Kupa');

    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($first['offer']->uuid, 2));
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($second['offer']->uuid, 1));

    $orders = checkOut();

    /*
     * ONE PURCHASE TO THE CUSTOMER, N ORDERS TO THE SELLERS. Each seller fulfils,
     * ships and is paid independently, which is why they cannot share a row.
     */
    expect($orders)->toHaveCount(2)
        ->and(collect($orders)->pluck('checkout_group_uuid')->unique())->toHaveCount(1)
        // Distinct numbers, because each is a separate commercial document.
        ->and(collect($orders)->pluck('order_number')->unique())->toHaveCount(2);

    $byOrg = collect($orders)->keyBy('selling_org_uuid');

    expect($byOrg[$first['org']->uuid]->items_total_minor)->toBe(20_000)
        ->and($byOrg[$second['org']->uuid]->items_total_minor)->toBe(5_000);
});

it('makes a single-seller basket one order, still in a group', function (): void {
    $fixture = checkoutFixture();
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid));

    $orders = checkOut();

    // The group is not a multi-seller special case — it is how a purchase is
    // identified, so a future Payment has one thing to charge against either way.
    expect($orders)->toHaveCount(1)
        ->and($orders[0]->checkout_group_uuid)->not->toBeEmpty();
});

it('empties the basket but keeps it', function (): void {
    $fixture = checkoutFixture();
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid));

    checkOut();

    // Leaving the lines would let the same basket be checked out twice; the cart
    // survives because the customer will shop again.
    expect(Cart::query()->forCustomer(1)->sole()->items()->count())->toBe(0)
        ->and(Cart::query()->forCustomer(1)->count())->toBe(1);
});

it('refuses to check out an empty basket', function (): void {
    checkoutAddresses();

    // An empty checkout would create a group with no orders in it and look like a
    // successful purchase.
    expect(fn () => checkOut())->toThrow(OrderException::class);
});

/*
|--------------------------------------------------------------------------
| The reservation (ADR-054) — Inventory's first real caller
|--------------------------------------------------------------------------
*/

it('HOLDS the stock at checkout without selling it', function (): void {
    $fixture = checkoutFixture(stock: 10);
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid, 3));

    checkOut();

    $inventory = app(InventoryQueryContract::class);

    /*
     * THE WHOLE POINT OF THE TWO-STEP. Nothing has left the seller's shelf —
     * on-hand is untouched — but three units are spoken for, so nobody else can
     * take them. A stock column on the Offer could never express this.
     */
    expect($inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(10)
        ->and($inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(7);
});

it('KEEPS the hold at placement — nothing is sold until it is paid (ADR-057)', function (): void {
    $fixture = checkoutFixture(stock: 10);
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid, 3));

    $orders = checkOut();
    app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    $inventory = app(InventoryQueryContract::class);

    /*
     * PLACEMENT USED TO COMMIT, and this assertion is where the amendment bites.
     * On-hand stays at 10 because nothing has left the seller's shelf — the
     * customer has said they mean it, not paid for it. Availability stays reduced,
     * so nobody else can take the units.
     *
     * The old behaviour left a cancelled placed order with nothing to give back
     * (Inventory has no un-commit), which is the gap ADR-057 closes by moving the
     * commit to Payment.
     */
    expect($inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(10)
        ->and($inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(7)
        ->and($orders[0]->fresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($orders[0]->fresh()->placed_at)->not->toBeNull();
});

it('RETURNS the stock when a PLACED order is cancelled — the gap ADR-057 closes', function (): void {
    $fixture = checkoutFixture(stock: 10);
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid, 3));

    $orders = checkOut();
    app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    app(CancelOrderAction::class)->run(
        $orders[0]->fresh(),
        new CancelOrderDTO(CancelOrderDTO::BY_CUSTOMER, 'Vazgeçtim'),
    );

    /*
     * THE TEST THAT COULD NOT HAVE PASSED BEFORE. Under commit-at-placement the
     * units were gone and `release()` on a committed reference was a no-op, so a
     * customer cancelling a placed order silently cost the seller three units. Now
     * the order was still HOLDING them, and cancelling is a plain release.
     */
    expect(app(InventoryQueryContract::class)
        ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(10)
        ->and($orders[0]->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('RELEASES the hold when a pending order is cancelled', function (): void {
    $fixture = checkoutFixture(stock: 10);
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid, 4));

    $orders = checkOut();
    app(CancelOrderAction::class)->run($orders[0], new CancelOrderDTO(CancelOrderDTO::BY_CUSTOMER, 'Vazgeçtim'));

    $inventory = app(InventoryQueryContract::class);

    // An abandoned checkout must not cost the seller a sale.
    expect($inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(10)
        ->and($orders[0]->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('reserves under a reference PER LINE, so a two-line order holds both', function (): void {
    /*
     * THE BUG THIS SHAPE EXISTS TO PREVENT. An Inventory reservation is one row on
     * a UNIQUE reference and reserving is idempotent on it — so two lines sharing
     * one reference would silently leave the second unheld, with nothing anywhere
     * saying so. One seller, two variants, both held: that is the assertion.
     */
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create();
    $first = ProductVariant::factory()->for($product)->create();
    $second = ProductVariant::factory()->for($product)->create();

    foreach ([$first, $second] as $variant) {
        $offer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
            variantUuid: $variant->uuid,
            sellingOrgId: $organization->getKey(),
            sellingOrgUuid: $organization->uuid,
            storeUuid: $store->uuid,
            priceMinor: 10_000,
            stockQuantity: 5,
        ));

        app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($offer->uuid, 2));
    }

    $orders = checkOut();

    expect($orders)->toHaveCount(1)
        ->and($orders[0]->lines)->toHaveCount(2);

    $inventory = app(InventoryQueryContract::class);

    foreach ([$first, $second] as $variant) {
        expect($inventory->availableFor($variant->uuid, $organization->uuid))->toBe(3);
    }
});

it('fails the WHOLE checkout and gives every hold back when one line cannot reserve', function (): void {
    $plenty = checkoutFixture(stock: 10, title: 'Tişört');
    $scarce = checkoutFixture(stock: 1, title: 'Kupa');

    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($plenty['offer']->uuid, 2));
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($scarce['offer']->uuid, 1));

    // Somebody else takes the scarce seller's last unit between adding and
    // checking out — the race a basket deliberately does not prevent.
    app(InventoryReservationContract::class)->reserve(
        $scarce['org']->uuid,
        $scarce['variant']->uuid,
        1,
        'baska-bir-checkout',
    );

    expect(fn () => checkOut())->toThrow(OrderException::class);

    /*
     * ALL-OR-NOTHING, BOTH HALVES. No orders survive (the transaction), and the
     * first seller's hold is back (the explicit release) — a reservation lives in
     * another module's table, written through a contract, so the transaction alone
     * could not be relied on to unwind it.
     */
    expect(Order::query()->count())->toBe(0)
        ->and(app(InventoryQueryContract::class)
            ->availableFor($plenty['variant']->uuid, $plenty['org']->uuid))->toBe(10);
});

it('refuses a line whose offer stopped being sellable after it was added', function (): void {
    $fixture = checkoutFixture();
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid));

    app(PauseOfferAction::class)->run($fixture['offer'], 'Stok bekleniyor');

    // The validation is repeated at checkout on purpose: minutes or days may have
    // passed, and the only check that matters is the one at the moment of
    // commitment.
    expect(fn () => checkOut())->toThrow(OrderException::class)
        ->and(Order::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The snapshot (ADR-053)
|--------------------------------------------------------------------------
*/

it('freezes the price, the title and the tax rate onto the line', function (): void {
    $fixture = checkoutFixture(priceMinor: 12_000, taxRatio: '0.2000', title: 'Pamuklu Tişört');
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid, 2));

    $orders = checkOut();
    $line = $orders[0]->lines->first();

    expect($line->unit_price_minor)->toBe(12_000)
        ->and($line->product_title)->toBe('Pamuklu Tişört')
        ->and($line->tax_rate)->toBe('0.2000')
        ->and($line->quantity)->toBe(2)
        ->and($line->line_total_minor)->toBe(24_000);
});

it('does not move a placed line when the seller re-prices or the catalog is renamed', function (): void {
    $fixture = checkoutFixture(priceMinor: 12_000, title: 'Pamuklu Tişört');
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid));

    $orders = checkOut();
    app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    // The world moves on.
    app(UpdateOfferPriceAction::class)->run($fixture['offer'], new UpdateOfferPriceDTO(priceMinor: 99_000));
    $fixture['product']->forceFill(['title_tr' => 'Bambaşka Bir Ad'])->save();

    $line = $orders[0]->fresh()->lines->first();

    /*
     * THE ADR, ASSERTED. An order is a financial and legal record: it must not
     * mutate when an upstream price or name changes, or every historical total and
     * invoice becomes unreproducible.
     */
    expect($line->unit_price_minor)->toBe(12_000)
        ->and($line->product_title)->toBe('Pamuklu Tişört')
        ->and($orders[0]->fresh()->grand_total_minor)->toBe(12_000);
});

it('snapshots shipping and billing separately, and neither follows the address book', function (): void {
    $fixture = checkoutFixture();
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid));

    $orders = checkOut();
    $order = $orders[0];

    // "Deliver to the office, invoice the home address" — the ordinary case
    // ADR-056 exists for.
    expect($order->shipping_address['label'])->toBe('Ev')
        ->and($order->billing_address['label'])->toBe('İş')
        ->and($order->shipping_address['district'])->toBe('Kadıköy')
        ->and($order->billing_address['district'])->toBe('Beşiktaş')
        // A snapshot containing a foreign key is not a snapshot.
        ->and($order->shipping_address)->not->toHaveKey('country_id')
        ->and($order->shipping_address['country_code'])->toBe('TR');
});

it('refuses an address that is not the acting customer’s', function (): void {
    $fixture = checkoutFixture();
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid));

    $theirs = app(CreateCustomerAddressAction::class)->run(2, 'baskasi', new CustomerAddressDTO(
        label: 'Ev', recipientName: 'Biri', phone: '+905550000000',
        line1: 'Bir Sokak 1', city: 'Ankara', countryCode: 'TR',
    ));

    $mine = checkoutAddresses(1);

    // "Not yours" and "does not exist" produce the same refusal — the difference
    // would confirm an address uuid is real.
    expect(fn () => app(CheckoutAction::class)->run(1, 'musteri', new CheckoutDTO(
        shippingAddressUuid: $theirs->uuid,
        billingAddressUuid: $mine['billing'],
    )))->toThrow(OrderException::class);

    expect(Order::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The tax (ADR-055, §3.4)
|--------------------------------------------------------------------------
*/

it('EXTRACTS the KDV from a tax-included price rather than adding it', function (): void {
    $fixture = checkoutFixture(priceMinor: 12_000, taxRatio: '0.2000');
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid));

    $orders = checkOut();
    $order = $orders[0];

    /*
     * 12 000 KDV-INCLUDED at %20 → 10 000 net + 2 000 tax. Getting this backwards
     * (adding %20 to 12 000 → 14 400) inflates every order by the rate, which is
     * why the direction is asserted rather than assumed.
     */
    expect($order->lines->first()->line_tax_minor)->toBe(2_000)
        ->and($order->items_total_minor)->toBe(12_000)
        ->and($order->tax_total_minor)->toBe(2_000)
        ->and($order->grand_total_minor)->toBe(12_000);
});

it('uses each product’s own bracket, so mixed rates come out right', function (): void {
    // A %1 book beside a %20 kettle — the case that makes an order-level tax
    // extraction impossible, and the reason it is done per line.
    $book = checkoutFixture(priceMinor: 10_100, taxRatio: '0.0100', title: 'Kitap');
    $kettle = checkoutFixture(priceMinor: 12_000, taxRatio: '0.2000', title: 'Su Isıtıcısı');

    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($book['offer']->uuid));
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($kettle['offer']->uuid));

    $orders = collect(checkOut())->keyBy('selling_org_uuid');

    expect($orders[$book['org']->uuid]->tax_total_minor)->toBe(100)
        ->and($orders[$kettle['org']->uuid]->tax_total_minor)->toBe(2_000);
});

it('charges no tax on a %0 bracket without dividing by anything', function (): void {
    // Exports and certain deliveries are genuinely zero-rated — a real bracket,
    // not a missing value.
    $fixture = checkoutFixture(priceMinor: 10_000, taxRatio: '0.0000');
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid));

    $orders = checkOut();

    expect($orders[0]->tax_total_minor)->toBe(0)
        ->and($orders[0]->grand_total_minor)->toBe(10_000);
});

it('keeps an order’s totals equal to the sum of its lines', function (): void {
    $fixture = checkoutFixture(priceMinor: 3_333, taxRatio: '0.2000');
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid, 3));

    $orders = checkOut();
    $order = $orders[0]->fresh();

    $computed = $order->computedTotals();

    expect($order->items_total_minor)->toBe($computed['items'])
        ->and($order->tax_total_minor)->toBe($computed['tax'])
        ->and($order->grand_total_minor)->toBe($computed['grand']);
});

/*
|--------------------------------------------------------------------------
| Placement and cancellation (§3.2, §3.3)
|--------------------------------------------------------------------------
*/

it('places every order of a purchase together', function (): void {
    $first = checkoutFixture(title: 'Tişört');
    $second = checkoutFixture(title: 'Kupa');

    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($first['offer']->uuid));
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($second['offer']->uuid));

    $orders = checkOut();
    $placed = app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    // The customer made ONE purchase; placing half of it is not a state anybody
    // asked for.
    expect($placed)->toHaveCount(2);

    foreach ($orders as $order) {
        expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
    }
});

it('refuses to place the same purchase twice', function (): void {
    $fixture = checkoutFixture();
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid, 2));

    $orders = checkOut();
    $group = $orders[0]->checkout_group_uuid;

    app(PlaceOrderAction::class)->run($group);

    expect(fn () => app(PlaceOrderAction::class)->run($group))->toThrow(OrderException::class);

    /*
     * AND THE STOCK DID NOT MOVE AT ALL. Since ADR-057 placement touches Inventory
     * not once — the hold checkout took is simply left standing — so a double
     * submit cannot double-anything. The group lock is now protecting the order
     * rows and the `OrderPlaced` events, which a future Payment will open charges
     * from.
     */
    $inventory = app(InventoryQueryContract::class);

    expect($inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(10)
        ->and($inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(8);
});

it('does not double-release on a double cancel', function (): void {
    $fixture = checkoutFixture(stock: 10);
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid, 3));

    $orders = checkOut();

    app(CancelOrderAction::class)->run($orders[0], new CancelOrderDTO(CancelOrderDTO::BY_CUSTOMER));
    app(CancelOrderAction::class)->run($orders[0]->fresh(), new CancelOrderDTO(CancelOrderDTO::BY_CUSTOMER));

    // A double-clicked button and a retried webhook are both ordinary; phantom
    // availability is not.
    expect(app(InventoryQueryContract::class)
        ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(10);
});

it('records who cancelled and whether a hold or a sale was undone', function (): void {
    Event::fake([OrderCancelled::class]);

    $fixture = checkoutFixture();
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid));

    $orders = checkOut();
    app(CancelOrderAction::class)->run($orders[0], new CancelOrderDTO(CancelOrderDTO::BY_SELLER, 'Stokta yok'));

    /*
     * Four different business events end the same way; the seller's notification,
     * the fraud signal and the abandonment metric all need to tell them apart.
     */
    Event::assertDispatched(OrderCancelled::class, function (OrderCancelled $event): bool {
        return $event->cancelledBy === CancelOrderDTO::BY_SELLER
            && $event->reason === 'Stokta yok'
            && $event->wasHoldingReservation === true;
    });
});

it('cannot be cancelled after it is already cancelled — silently, not by refusing', function (): void {
    $fixture = checkoutFixture();
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid));

    $orders = checkOut();
    app(CancelOrderAction::class)->run($orders[0], new CancelOrderDTO(CancelOrderDTO::BY_CUSTOMER, 'İlk'));
    $again = app(CancelOrderAction::class)->run($orders[0]->fresh(), new CancelOrderDTO(CancelOrderDTO::BY_ADMIN, 'İkinci'));

    // The caller's intent is already satisfied — and the FIRST reason stands,
    // because that is the one that actually stopped the order.
    expect($again->status)->toBe(OrderStatus::Cancelled)
        ->and($again->cancellation_reason)->toBe('İlk');
});

/*
|--------------------------------------------------------------------------
| The expiry sweep (§3.3)
|--------------------------------------------------------------------------
*/

it('gives back the stock of a checkout nobody finished', function (): void {
    $fixture = checkoutFixture(stock: 10);
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid, 4));

    $orders = checkOut();

    // The customer closes the tab. Nobody abandons a basket on purpose and then
    // tidies up, so without this job the units are gone for good.
    $orders[0]->forceFill([
        'created_at' => now()->subMinutes((int) config('order.reservation.expires_after_minutes') + 5),
    ])->save();

    app(ExpireReservationsJob::class)->handle(
        app(\App\Modules\Order\Domain\Contracts\OrderRepositoryContract::class),
        app(CancelOrderAction::class),
    );

    expect($orders[0]->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(app(InventoryQueryContract::class)
            ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(10);
});

it('leaves a fresh checkout and a placed order alone', function (): void {
    $fresh = checkoutFixture(title: 'Tişört');
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fresh['offer']->uuid));
    $freshOrders = checkOut(1);

    $placed = checkoutFixture(title: 'Kupa');
    app(AddCartItemAction::class)->run(2, 'ikinci', new AddCartItemDTO($placed['offer']->uuid));
    $placedOrders = checkOut(2);
    app(PlaceOrderAction::class)->run($placedOrders[0]->checkout_group_uuid);

    $placedOrders[0]->forceFill([
        'created_at' => now()->subMinutes((int) config('order.reservation.expires_after_minutes') + 5),
    ])->save();

    app(ExpireReservationsJob::class)->handle(
        app(\App\Modules\Order\Domain\Contracts\OrderRepositoryContract::class),
        app(CancelOrderAction::class),
    );

    /*
     * A PLACED ORDER IS NEVER SWEPT (ADR-057), and the reason changed with the
     * amendment: it is not that its stock has committed — it holds a reservation
     * just like the fresh one — but that it is not an abandoned tab. The customer
     * believes they have made this purchase, and it holds until paid or cancelled,
     * however long that takes. Only an UNPLACED checkout expires.
     */
    expect($freshOrders[0]->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($placedOrders[0]->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
});

it('marks a swept order as expired, not as the customer’s doing', function (): void {
    Event::fake([OrderCancelled::class]);

    $fixture = checkoutFixture();
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($fixture['offer']->uuid));

    $orders = checkOut();
    $orders[0]->forceFill([
        'created_at' => now()->subMinutes((int) config('order.reservation.expires_after_minutes') + 5),
    ])->save();

    app(ExpireReservationsJob::class)->handle(
        app(\App\Modules\Order\Domain\Contracts\OrderRepositoryContract::class),
        app(CancelOrderAction::class),
    );

    // The one cancellation a seller most needs told apart from a customer changing
    // their mind — and the sweep has no actor to derive it from.
    Event::assertDispatched(OrderCancelled::class, fn (OrderCancelled $event): bool => $event->cancelledBy === CancelOrderDTO::BY_EXPIRY);
});

/*
|--------------------------------------------------------------------------
| Events (§6)
|--------------------------------------------------------------------------
*/

it('announces the purchase once and each placed order separately', function (): void {
    Event::fake([CartCheckedOut::class, OrderPlaced::class]);

    $first = checkoutFixture(priceMinor: 10_000, title: 'Tişört');
    $second = checkoutFixture(priceMinor: 5_000, title: 'Kupa');

    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($first['offer']->uuid));
    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($second['offer']->uuid));

    $orders = checkOut();

    /*
     * ONE EVENT FOR THE PURCHASE — a "thanks for your order" email is one message
     * about a checkout group — carrying the total no single order holds (ADR-052).
     */
    Event::assertDispatchedTimes(CartCheckedOut::class, 1);
    Event::assertDispatched(CartCheckedOut::class, fn (CartCheckedOut $event): bool => count($event->orderUuids) === 2 && $event->grandTotalMinor === 15_000);

    app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    // ONE PER ORDER at placement, because everything downstream of it is per
    // seller.
    Event::assertDispatchedTimes(OrderPlaced::class, 2);
});
