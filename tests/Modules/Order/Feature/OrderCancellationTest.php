<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CancelOrderAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Application\Jobs\ExpireReservationsJob;
use App\Modules\Order\Domain\Contracts\OrderRepositoryContract;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CancelOrderDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Events\OrderCancelledBySeller;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Actor-typed cancellation (ADR-057, §3.3)
|--------------------------------------------------------------------------
|
| "Cancelled" was one word for four different business events, and this file is
| about the difference between them. All four release the hold — that part is now
| uniform, because placement no longer commits (group A). What varies is what the
| cancellation SAYS ABOUT THE SELLER'S SHELF:
|
|   BUYER            they changed their mind. The seller has the goods.
|   SELLER           they cannot fulfil — so they have none, and the platform
|                    should stop selling it (group C does the zeroing).
|   ADMIN            oversight. Not a claim about anyone's stock, unless told.
|   SYSTEM/EXPIRY    an abandoned tab. Says nothing about the shelf.
|
| The actor is now RECORDED ON THE ORDER, not only on the event: an event is
| consumed once, while "I never cancelled that" is asked six months later.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A placed order with real stock behind it.
 *
 * PLACED, not merely checked out, because that is the case ADR-057 exists for:
 * before it, a placed order's stock had committed and cancelling could not give
 * it back.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{order: Order, org: Organization, variant: ProductVariant, offer: \App\Modules\Offer\Domain\Models\Offer}
 */
function cancellableOrder(int $stock = 10, int $quantity = 3, bool $place = true): array
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

    if ($place) {
        app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);
    }

    return [
        'order' => $orders[0]->fresh(),
        'org' => $organization,
        'variant' => $variant,
        'offer' => $offer,
    ];
}

/**
 * @param  array{order: Order, org: Organization, variant: ProductVariant, offer: \App\Modules\Offer\Domain\Models\Offer}  $fixture
 */
function availableFor(array $fixture): int
{
    return app(InventoryQueryContract::class)
        ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid);
}

/*
|--------------------------------------------------------------------------
| Every actor returns the stock
|--------------------------------------------------------------------------
*/

it('returns the stock when the BUYER cancels a placed order', function (): void {
    $fixture = cancellableOrder(stock: 10, quantity: 3);

    expect(availableFor($fixture))->toBe(7);

    app(CancelOrderAction::class)->run($fixture['order'], new CancelOrderDTO(
        cancelledBy: CancelOrderDTO::BY_CUSTOMER,
        reason: 'Vazgeçtim',
    ));

    // They changed their mind; the seller still has the goods and they go back on
    // sale immediately.
    expect(availableFor($fixture))->toBe(10)
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('returns the stock when an ADMIN cancels, and says nothing about the shelf', function (): void {
    $fixture = cancellableOrder(stock: 10, quantity: 3);

    Event::fake([OrderCancelledBySeller::class]);

    app(CancelOrderAction::class)->run($fixture['order'], new CancelOrderDTO(
        cancelledBy: CancelOrderDTO::BY_ADMIN,
        reason: 'Ödeme anlaşmazlığı',
    ));

    /*
     * THE CONSERVATIVE DEFAULT. An oversight cancellation is not a claim about
     * anybody's stock — zeroing every one of them would take a merchant's variant
     * off the platform because somebody upstream was arbitrating a dispute.
     */
    expect(availableFor($fixture))->toBe(10);
    Event::assertNotDispatched(OrderCancelledBySeller::class);
});

it('returns the stock when an abandoned checkout EXPIRES', function (): void {
    $fixture = cancellableOrder(stock: 10, quantity: 3, place: false);

    $fixture['order']->forceFill([
        'created_at' => now()->subMinutes((int) config('order.reservation.expires_after_minutes') + 5),
    ])->save();

    Event::fake([OrderCancelledBySeller::class]);

    app(ExpireReservationsJob::class)->handle(
        app(OrderRepositoryContract::class),
        app(CancelOrderAction::class),
    );

    // An abandoned tab says nothing about the shelf either — the seller never even
    // knew about it.
    expect(availableFor($fixture))->toBe(10)
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Cancelled);

    Event::assertNotDispatched(OrderCancelledBySeller::class);
});

it('never sweeps a PLACED order, however old (ADR-057)', function (): void {
    $fixture = cancellableOrder(stock: 10, quantity: 3);

    $fixture['order']->forceFill([
        'created_at' => now()->subMinutes((int) config('order.reservation.expires_after_minutes') + 500),
    ])->save();

    app(ExpireReservationsJob::class)->handle(
        app(OrderRepositoryContract::class),
        app(CancelOrderAction::class),
    );

    /*
     * A placed order holds a reservation too, but it is not an abandoned tab: it is
     * a purchase the customer believes they have made. Expiring one would cancel
     * somebody's order to free stock they are about to pay for.
     */
    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(availableFor($fixture))->toBe(7);
});

/*
|--------------------------------------------------------------------------
| The actor is recorded
|--------------------------------------------------------------------------
*/

it('records who cancelled, on the order and not only on the event', function (string $actor): void {
    $fixture = cancellableOrder();

    app(CancelOrderAction::class)->run($fixture['order'], new CancelOrderDTO(
        cancelledBy: $actor,
        reason: 'Gerekçe',
    ));

    /*
     * An event is consumed once. This is the fact somebody asks about six months
     * later, when a customer says "I never cancelled that" and the platform has to
     * answer from its own records rather than from a listener's side effect.
     */
    expect($fixture['order']->fresh()->cancelled_by)->toBe($actor)
        ->and($fixture['order']->fresh()->cancellation_reason)->toBe('Gerekçe');
})->with([
    CancelOrderDTO::BY_CUSTOMER,
    CancelOrderDTO::BY_SELLER,
    CancelOrderDTO::BY_ADMIN,
    CancelOrderDTO::BY_EXPIRY,
]);

it('records the expiry sweep as the canceller, not the customer', function (): void {
    $fixture = cancellableOrder(place: false);

    $fixture['order']->forceFill([
        'created_at' => now()->subMinutes((int) config('order.reservation.expires_after_minutes') + 5),
    ])->save();

    app(ExpireReservationsJob::class)->handle(
        app(OrderRepositoryContract::class),
        app(CancelOrderAction::class),
    );

    // The one cancellation a seller most needs told apart from a customer changing
    // their mind — and the sweep has no actor to derive it from.
    expect($fixture['order']->fresh()->cancelled_by)->toBe(CancelOrderDTO::BY_EXPIRY);
});

/*
|--------------------------------------------------------------------------
| The seller-fault intent
|--------------------------------------------------------------------------
*/

it('treats a SELLER cancellation as a claim about their stock, always', function (): void {
    $fixture = cancellableOrder();

    Event::fake([OrderCancelledBySeller::class]);

    // The flag is deliberately NOT passed — a surface that forgot it must not
    // silently re-list goods the seller has just said they do not have.
    app(CancelOrderAction::class)->run($fixture['order'], new CancelOrderDTO(
        cancelledBy: CancelOrderDTO::BY_SELLER,
        reason: 'Stokta kalmadı',
    ));

    Event::assertDispatched(OrderCancelledBySeller::class, fn (OrderCancelledBySeller $event): bool
        => $event->offerUuid === $fixture['offer']->uuid
        && $event->variantUuid === $fixture['variant']->uuid
        && $event->sellingOrgUuid === $fixture['org']->uuid);
});

it('lets an ADMIN opt into the seller-fault case explicitly', function (): void {
    $fixture = cancellableOrder();

    Event::fake([OrderCancelledBySeller::class]);

    app(CancelOrderAction::class)->run($fixture['order'], new CancelOrderDTO(
        cancelledBy: CancelOrderDTO::BY_ADMIN,
        reason: 'Satıcıda hiç yokmuş',
        zeroSellerStock: true,
    ));

    // "They never had it" does exactly what the seller's own cancellation does,
    // through the same event — opt-in, because an operator taking a merchant's
    // listing down should have to mean it.
    Event::assertDispatched(OrderCancelledBySeller::class);
});

it('announces the seller claim per LINE, because it is about a variant', function (): void {
    // One seller, two variants in one order.
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create([
        'tax_rate_id' => TaxRate::factory()->rate('0.2000')->create()->getKey(),
    ]);

    foreach ([1, 2] as $i) {
        $variant = ProductVariant::factory()->for($product)->create();
        $offer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
            variantUuid: $variant->uuid,
            sellingOrgId: $organization->getKey(),
            sellingOrgUuid: $organization->uuid,
            storeUuid: $store->uuid,
            priceMinor: 10_000,
            stockQuantity: 5,
        ));
        app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO($offer->uuid, 1));
    }

    $address = app(CreateCustomerAddressAction::class)->run(1, 'musteri', new CustomerAddressDTO(
        label: 'Ev', recipientName: 'Ayşe', phone: '+905551234567',
        line1: 'Bağdat Caddesi 120', city: 'İstanbul', countryCode: 'TR',
    ));

    $orders = app(CheckoutAction::class)->run(1, 'musteri', new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    Event::fake([OrderCancelledBySeller::class]);

    app(CancelOrderAction::class)->run($orders[0], new CancelOrderDTO(
        cancelledBy: CancelOrderDTO::BY_SELLER,
        reason: 'Depoda yok',
    ));

    /*
     * "The seller has none" is a claim about a VARIANT, not about an order. An
     * order may carry several, and each one's offer has to be zeroed
     * independently — one event naming the order would make the consumer resolve
     * the lines, which it cannot do without importing Order.
     */
    Event::assertDispatchedTimes(OrderCancelledBySeller::class, 2);
});

/*
|--------------------------------------------------------------------------
| Idempotence
|--------------------------------------------------------------------------
*/

it('does not double-release, whichever actor repeats', function (): void {
    $fixture = cancellableOrder(stock: 10, quantity: 3);

    app(CancelOrderAction::class)->run($fixture['order'], new CancelOrderDTO(CancelOrderDTO::BY_CUSTOMER));
    app(CancelOrderAction::class)->run($fixture['order']->fresh(), new CancelOrderDTO(CancelOrderDTO::BY_ADMIN));

    // A double-clicked button and a retried webhook are both ordinary; phantom
    // availability is not. The FIRST actor and reason stand — they are the ones
    // that actually stopped the order.
    expect(availableFor($fixture))->toBe(10)
        ->and($fixture['order']->fresh()->cancelled_by)->toBe(CancelOrderDTO::BY_CUSTOMER);
});

it('does not re-announce a seller claim on a repeated cancellation', function (): void {
    $fixture = cancellableOrder();

    app(CancelOrderAction::class)->run($fixture['order'], new CancelOrderDTO(CancelOrderDTO::BY_SELLER, 'Yok'));

    Event::fake([OrderCancelledBySeller::class]);

    app(CancelOrderAction::class)->run($fixture['order']->fresh(), new CancelOrderDTO(CancelOrderDTO::BY_SELLER, 'Yine yok'));

    // Nothing changed, so nothing is announced — re-zeroing an already-zero offer
    // would write a second pointless stock movement into the seller's ledger.
    Event::assertNotDispatched(OrderCancelledBySeller::class);
});
