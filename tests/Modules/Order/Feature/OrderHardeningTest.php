<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\StockReservation;
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
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Support\IncludedTax;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| Hardening — the seam, read from the OTHER side (§P7)
|--------------------------------------------------------------------------
|
| Every other Order test asserts what Order believes. This file asserts what
| INVENTORY ended up holding — the same facts checked from the module that
| actually owns them, because the two agreeing is the whole claim of ADR-054 and
| the only thing that would catch a reference derived one way and rebuilt another.
|
| That failure mode deserves naming: reserve under `{order}:{variant}` and commit
| under `{order}` and the stock leaves AND the hold survives forever, with every
| number in Order looking perfectly correct. No test written from Order's side
| can see it.
|
| Plus the two drift-catchers a module accumulates: the tax arithmetic pinned
| against hand-computed values, and the strings a seller reads.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A checked-out order with real stock behind it.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{order: Order, org: Organization, variant: ProductVariant}
 */
function hardenedOrder(int $stock = 10, int $quantity = 3): array
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
        stockQuantity: $stock,
    ));

    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($offer->uuid, $quantity));

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

    return ['order' => $orders[0], 'org' => $organization, 'variant' => $variant];
}

/*
|--------------------------------------------------------------------------
| The reservation seam, verified from Inventory's records
|--------------------------------------------------------------------------
*/

it('records the hold in Inventory under the reference Order derives', function (): void {
    ['order' => $order, 'variant' => $variant] = hardenedOrder(quantity: 3);

    $reservation = StockReservation::query()->sole();

    /*
     * THE CONTRACT BETWEEN THE TWO MODULES, asserted as a string. Inventory stored
     * this; Order must rebuild the identical one at commit and release, and it
     * rebuilds it from the order uuid and the line's variant — never from a
     * column, so there is nothing to migrate and nothing to get out of step.
     */
    expect($reservation->reference_uuid)->toBe($order->reservationReferenceFor($variant->uuid))
        ->and($reservation->quantity)->toBe(3)
        ->and($reservation->status)->toBe(ReservationStatus::Active);
});

it('writes a Reserved movement that moves reserved and not on-hand', function (): void {
    ['order' => $order] = hardenedOrder(stock: 10, quantity: 3);

    $movement = StockMovement::query()
        ->where('type', StockMovementType::Reserved->value)
        ->sole();

    // Nothing left the seller's shelf — the units are spoken for. The ledger says
    // which of the two happened, which a bare counter never could (ADR-050).
    expect($movement->on_hand_delta)->toBe(0)
        ->and($movement->reserved_delta)->toBe(3)
        ->and($movement->reference)->toBe($order->reservationReferenceFor(
            $order->lines->first()->variant_uuid,
        ));
});

it('writes NO movement at placement and leaves the hold Active (ADR-057)', function (): void {
    ['order' => $order] = hardenedOrder(stock: 10, quantity: 3);

    $before = StockMovement::query()->count();

    app(PlaceOrderAction::class)->run($order->checkout_group_uuid);

    /*
     * READ FROM INVENTORY'S SIDE, which is the only place the amendment is fully
     * visible: placement writes nothing to the ledger at all, and the reservation
     * is still `Active`. Under the old behaviour there would be a `Committed`
     * movement here moving both counters, and the hold would be spent.
     *
     * Commit becomes Payment's — a successful charge is what makes units leave.
     */
    expect(StockMovement::query()->count())->toBe($before)
        ->and(StockMovement::query()->where('type', StockMovementType::Committed->value)->count())->toBe(0)
        ->and(StockReservation::query()->sole()->status)->toBe(ReservationStatus::Active);
});

it('turns the hold into a Released movement on cancellation', function (): void {
    ['order' => $order] = hardenedOrder(stock: 10, quantity: 3);

    app(CancelOrderAction::class)->run($order, new CancelOrderDTO(CancelOrderDTO::BY_CUSTOMER));

    $released = StockMovement::query()
        ->where('type', StockMovementType::Released->value)
        ->sole();

    // Only `reserved` moves back: nothing physical ever went anywhere.
    expect($released->on_hand_delta)->toBe(0)
        ->and($released->reserved_delta)->toBe(-3)
        ->and(StockReservation::query()->sole()->status)->toBe(ReservationStatus::Released);
});

it('leaves the ledger summing to the projection through a whole purchase', function (): void {
    ['order' => $order, 'variant' => $variant, 'org' => $org] = hardenedOrder(stock: 10, quantity: 3);

    app(PlaceOrderAction::class)->run($order->checkout_group_uuid);

    $item = \App\Modules\Inventory\Domain\Models\StockItem::query()
        ->forSellingOrg($org->uuid)->forVariant($variant->uuid)->sole();

    /*
     * ADR-050's invariant, exercised by a real checkout rather than by Inventory's
     * own fixtures: the append-only ledger is the source of truth and the two
     * counters are projections of it. If Order drove the contract in a way
     * Inventory did not expect, this is where the arithmetic stops adding up.
     *
     * After a PLACED purchase (ADR-057) the seller still has all ten units and
     * three of them are spoken for — nothing has been sold, because nothing has
     * been paid.
     */
    $movements = StockMovement::query()->where('stock_item_id', $item->getKey())->get();

    expect((int) $movements->sum('on_hand_delta'))->toBe($item->on_hand)
        ->and((int) $movements->sum('reserved_delta'))->toBe($item->reserved)
        ->and($item->on_hand)->toBe(10)
        ->and($item->reserved)->toBe(3);
});

it('does not double-release when a cancellation is retried', function (): void {
    ['order' => $order] = hardenedOrder(stock: 10, quantity: 3);

    app(CancelOrderAction::class)->run($order, new CancelOrderDTO(CancelOrderDTO::BY_CUSTOMER));
    app(CancelOrderAction::class)->run($order->fresh(), new CancelOrderDTO(CancelOrderDTO::BY_CUSTOMER));

    // ONE release movement, not two. Phantom availability is how a seller ends up
    // overselling after a double-clicked button.
    expect(StockMovement::query()->where('type', StockMovementType::Released->value)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The tax arithmetic (§3.4, ADR-055)
|--------------------------------------------------------------------------
*/

it('extracts the included KDV at every bracket', function (string $rate, int $total, int $expected): void {
    // Hand-computed, so the test would still be right if the implementation were
    // rewritten — the point of pinning arithmetic rather than asserting it against
    // itself.
    expect(IncludedTax::of($total, $rate))->toBe($expected);
})->with([
    // 12 000 at %20 → 10 000 + 2 000.
    ['0.2000', 12_000, 2_000],
    // 11 000 at %10 → 10 000 + 1 000.
    ['0.1000', 11_000, 1_000],
    // 10 100 at %1 → 10 000 + 100.
    ['0.0100', 10_100, 100],
    // A real bracket, not a missing value: exports are genuinely zero-rated.
    ['0.0000', 10_000, 0],
    // Rounding lands on the nearest kuruş rather than truncating.
    ['0.2000', 100, 17],
    ['0.2000', 1, 0],
]);

it('never adds tax on top — the direction that would inflate every order', function (): void {
    $total = 12_000;
    $tax = IncludedTax::of($total, '0.2000');

    /*
     * The extracted tax is a PART of the total, not an addition to it (ADR-042).
     * Getting the direction backwards produces 2 400 here instead of 2 000 and
     * inflates the platform's every invoice by the rate.
     */
    expect($tax)->toBeLessThan($total)
        ->and($total - $tax)->toBe(10_000)
        ->and($tax)->not->toBe(2_400);
});

it('scales a rate to an integer so no amount meets a float', function (): void {
    expect(IncludedTax::scale('0.2000'))->toBe(2_000)
        ->and(IncludedTax::scale('0.0100'))->toBe(100)
        ->and(IncludedTax::SCALE)->toBe(10_000);
});

/*
|--------------------------------------------------------------------------
| Totals and immutability, end to end
|--------------------------------------------------------------------------
*/

it('keeps a placed order’s totals equal to the sum of its lines', function (): void {
    ['order' => $order] = hardenedOrder(quantity: 3);

    app(PlaceOrderAction::class)->run($order->checkout_group_uuid);

    $fresh = $order->fresh();
    $computed = $fresh->computedTotals();

    expect($fresh->items_total_minor)->toBe($computed['items'])
        ->and($fresh->tax_total_minor)->toBe($computed['tax'])
        ->and($fresh->grand_total_minor)->toBe($computed['grand'])
        ->and($fresh->status)->toBe(OrderStatus::AwaitingPayment);
});

it('refuses to let anything rewrite a placed line', function (): void {
    ['order' => $order] = hardenedOrder();

    app(PlaceOrderAction::class)->run($order->checkout_group_uuid);

    $line = $order->fresh()->lines->first();
    $line->update(['unit_price_minor' => 1, 'product_title' => 'Başka Bir Şey']);
    $line->delete();

    // Enforced in the model, not by convention: a financial record that can be
    // edited is not one (ADR-053).
    expect($line->fresh()->unit_price_minor)->toBe(12_000)
        ->and($line->fresh()->product_title)->not->toBe('Başka Bir Şey')
        ->and($line->fresh())->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The strings a human reads
|--------------------------------------------------------------------------
*/

it('labels every order status in both languages', function (): void {
    foreach (OrderStatus::cases() as $status) {
        $key = "enums.OrderStatus.{$status->value}";

        /*
         * A missing key does not throw — Laravel returns the KEY. So the failure
         * mode is a seller reading `enums.OrderStatus.awaiting_payment` on their
         * order list, invisible to every other test and to code review.
         */
        expect(__($key, [], 'tr'))->not->toBe($key, "missing tr label: {$key}")
            ->and(__($key, [], 'en'))->not->toBe($key, "missing en label: {$key}");
    }
});

it('keeps the two order language files the same shape', function (): void {
    $flatten = function (array $strings, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($strings as $key => $value) {
            $keys = is_array($value)
                ? [...$keys, ...$flatten($value, "{$prefix}{$key}.")]
                : [...$keys, "{$prefix}{$key}"];
        }

        return $keys;
    };

    $tr = $flatten(require base_path('lang/tr/order.php'));
    $en = $flatten(require base_path('lang/en/order.php'));

    sort($tr);
    sort($en);

    // A string added to one file and forgotten in the other renders as a raw key
    // to whoever runs the panel in the other locale — and only for them, which is
    // exactly the bug nobody reproduces.
    expect($en)->toBe($tr);
});
