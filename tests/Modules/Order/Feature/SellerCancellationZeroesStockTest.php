<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Application\Actions\SuspendOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CancelOrderAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CancelOrderDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Seller-cancel zeroes the stock, at the source (ADR-057, group C)
|--------------------------------------------------------------------------
|
| THREE MODULES TAKE PART AND NONE OF THEM NAMES ANOTHER'S CLASS. Order emits
| `OrderCancelledBySeller`; the Offer consumes it BY CLASS-STRING and writes zero
| through its own stock action; that action's `OfferStockChanged` flows through the
| existing Offer→Inventory mirror (ADR-048) to `on_hand`; and the buy box, reading
| availability from Inventory, drops the listing.
|
| So this file has to be an end-to-end test. Each module's own tests prove their
| half; only a real cancellation proves the chain, because every link is a NAME
| resolved at runtime rather than a class the compiler checks.
|
| WHY ZERO AT ALL, rather than just releasing: a merchant who cannot fulfil has
| told the platform something it did not know — they have none. Releasing alone
| would put the units straight back on sale and send the next buyer into the same
| wall, and the one after that.
|
| WHY AT THE OFFER: the seller declares stock there (ADR-048). Zeroing anywhere
| else would leave their own screen showing ten while the storefront showed none.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * One seller, one live offer, one placed order.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{order: Order, org: Organization, variant: ProductVariant, offer: Offer, product: Product}
 */
function sellerCancelFixture(int $stock = 10, int $quantity = 3): array
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

    app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    return [
        'order' => $orders[0]->fresh(),
        'org' => $organization,
        'variant' => $variant,
        'offer' => $offer,
        'product' => $product,
    ];
}

function cancelAsSeller(Order $order, string $reason = 'Depoda kalmamış'): void
{
    app(CancelOrderAction::class)->run($order, new CancelOrderDTO(
        cancelledBy: CancelOrderDTO::BY_SELLER,
        reason: $reason,
    ));
}

/*
|--------------------------------------------------------------------------
| The chain, end to end
|--------------------------------------------------------------------------
*/

it('zeroes the offer, the on-hand and the buy box in one cancellation', function (): void {
    $fixture = sellerCancelFixture(stock: 10, quantity: 3);

    cancelAsSeller($fixture['order']);

    $inventory = app(InventoryQueryContract::class);

    /*
     * ALL THREE, FROM ONE EVENT. The Offer's own column is what the seller sees on
     * their form; `on_hand` is what Inventory holds; the buy box is what a shopper
     * hits. If any one of them disagreed the seller would believe the wrong number.
     */
    expect($fixture['offer']->fresh()->stock_quantity)->toBe(0)
        ->and($inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(0)
        ->and($inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(0)
        ->and(app(OfferQueryContract::class)->featuredOfferForProduct($fixture['product']->uuid))->toBeNull();
});

it('leaves the offer ACTIVE, so the seller restocks from their normal form', function (): void {
    $fixture = sellerCancelFixture();

    cancelAsSeller($fixture['order']);

    /*
     * OUT-OF-STOCK IS NOT A STATUS (ADR-043/045). Pausing the offer would leave the
     * seller hunting for a pause they did not apply; zero stock on an Active offer
     * is a state they already know how to leave — they type a number.
     */
    expect($fixture['offer']->fresh()->status)->toBe(OfferStatus::Active);
});

it('writes the zero through the seller’s own stock path, audit and all', function (): void {
    $fixture = sellerCancelFixture();

    cancelAsSeller($fixture['order']);

    /*
     * INDISTINGUISHABLE FROM THE SELLER TYPING 0, deliberately: same action, same
     * audit entry, same `OfferStockChanged`, same mirror. A listener that wrote the
     * column directly would be a second way for stock to change, and the two would
     * drift.
     *
     * The audit reason is what stops this reading as an unexplained edit on the
     * seller's own record.
     */
    $audit = $fixture['offer']->fresh()->audits()->latest('id')->first();

    // The WHY rides in `metadata`, which is where `AuditContext::withReason()`
    // puts an explained change — a self-service edit carries none, so its presence
    // is itself the signal that something acted on the seller's behalf.
    expect($audit)->not->toBeNull()
        ->and($audit->new_values)->toHaveKey('stock_quantity')
        ->and($audit->new_values['stock_quantity'])->toBe(0)
        ->and($audit->metadata['reason'] ?? '')->toContain($fixture['order']->order_number);
});

it('returns this order’s hold as well as zeroing — both halves', function (): void {
    $fixture = sellerCancelFixture(stock: 10, quantity: 3);

    cancelAsSeller($fixture['order']);

    /*
     * The release happens too (group B). It is invisible in the final numbers
     * because the zero dominates — but a reservation left standing against a
     * zeroed pool would make `available` go negative if Inventory did not clamp,
     * and would strand the hold forever.
     */
    expect(\App\Modules\Inventory\Domain\Models\StockReservation::query()->sole()->status)
        ->toBe(\App\Modules\Inventory\Domain\Enums\ReservationStatus::Released)
        ->and(app(InventoryQueryContract::class)
            ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Isolation — one seller's shelf is not another's
|--------------------------------------------------------------------------
*/

it('never touches a SECOND seller’s offer for the same variant', function (): void {
    $first = sellerCancelFixture(stock: 10, quantity: 3);

    // A competitor selling the identical variant — the whole point of a shared
    // catalog (ADR-037).
    $rival = Organization::factory()->create();
    $rivalStore = Store::factory()->create([
        'organization_id' => $rival->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $rivalOffer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $first['variant']->uuid,
        sellingOrgId: $rival->getKey(),
        sellingOrgUuid: $rival->uuid,
        storeUuid: $rivalStore->uuid,
        priceMinor: 13_000,
        stockQuantity: 8,
    ));

    cancelAsSeller($first['order']);

    /*
     * ONE SELLER RUNNING OUT SAYS NOTHING ABOUT ANYBODY ELSE'S SHELF. The event
     * names an OFFER, not a variant, precisely so this cannot go wrong — and the
     * rival should now win a buy box the first seller has dropped out of.
     */
    expect($rivalOffer->fresh()->stock_quantity)->toBe(8)
        ->and(app(InventoryQueryContract::class)->onHandFor($first['variant']->uuid, $rival->uuid))->toBe(8)
        ->and(app(OfferQueryContract::class)->featuredOfferForProduct($first['product']->uuid)['uuid'])
        ->toBe($rivalOffer->uuid);
});

it('does not zero the seller’s OTHER offers', function (): void {
    $fixture = sellerCancelFixture();

    // Same seller, a different variant — untouched, because the claim is about
    // one thing they ran out of, not about their whole catalogue.
    $otherVariant = ProductVariant::factory()->for($fixture['product'])->create();
    $otherOffer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $otherVariant->uuid,
        sellingOrgId: $fixture['org']->getKey(),
        sellingOrgUuid: $fixture['org']->uuid,
        storeUuid: Store::query()->where('organization_id', $fixture['org']->getKey())->value('uuid'),
        priceMinor: 9_000,
        stockQuantity: 5,
    ));

    cancelAsSeller($fixture['order']);

    expect($otherOffer->fresh()->stock_quantity)->toBe(5);
});

/*
|--------------------------------------------------------------------------
| The listener's own edges
|--------------------------------------------------------------------------
*/

it('does nothing when the offer is already at zero', function (): void {
    $fixture = sellerCancelFixture(stock: 3, quantity: 3);

    // The order took the seller's last three, so a second seller-cancel would find
    // nothing to zero.
    cancelAsSeller($fixture['order']);
    $movementsAfterFirst = \App\Modules\Inventory\Domain\Models\StockMovement::query()->count();

    app(\App\Modules\Offer\Application\Listeners\ZeroStockOnSellerCancellation::class)->handle(
        new \App\Modules\Order\Domain\Events\OrderCancelledBySeller(
            $fixture['order']->uuid,
            $fixture['order']->order_number,
            $fixture['offer']->uuid,
            $fixture['variant']->uuid,
            $fixture['org']->uuid,
        ),
    );

    // Re-writing zero would put a second movement with a zero delta into the one
    // place a seller goes to understand where their stock went (ADR-050).
    expect(\App\Modules\Inventory\Domain\Models\StockMovement::query()->count())->toBe($movementsAfterFirst);
});

it('ignores a payload that does not name an offer', function (): void {
    $listener = app(\App\Modules\Offer\Application\Listeners\ZeroStockOnSellerCancellation::class);

    // Reached by NAME, so a renamed property or somebody else's event is a live
    // possibility: doing nothing beats a fatal.
    $listener->handle(new class {});
    $listener->handle(new class
    {
        public string $offerUuid = 'yok-boyle-bir-teklif';
    });

    expect(Offer::query()->count())->toBe(0);
});

it('leaves a SUSPENDED offer alone rather than forcing a write', function (): void {
    $fixture = sellerCancelFixture();

    app(SuspendOfferAction::class)->run(
        $fixture['offer'],
        $this->actingAsAdmin(),
        'Aldatıcı fiyat',
    );

    cancelAsSeller($fixture['order']->fresh());

    /*
     * A suspended offer sells nothing, so there is no oversell to prevent — and
     * forcing the write would edit an offer its seller is not allowed to touch.
     * The action's own refusal is the right answer, not an obstacle.
     */
    expect($fixture['offer']->fresh()->stock_quantity)->toBe(10)
        ->and($fixture['order']->fresh()->status)
        ->toBe(\App\Modules\Order\Domain\Enums\OrderStatus::Cancelled);
});

/*
|--------------------------------------------------------------------------
| The boundary
|--------------------------------------------------------------------------
*/

it('subscribes to Order’s event by class-string, so nothing imports anything', function (): void {
    /*
     * Three modules take part and none names another's class. The subscription is
     * a STRING, so nothing but this test would notice if the wiring were dropped or
     * the event renamed — which is exactly the cost the strictest boundary on the
     * platform buys.
     */
    expect(Event::hasListeners('App\Modules\Order\Domain\Events\OrderCancelledBySeller'))->toBeTrue();
});
