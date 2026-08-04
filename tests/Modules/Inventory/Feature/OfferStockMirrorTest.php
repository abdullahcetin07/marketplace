<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Application\Actions\UpdateOfferStockAction;
use App\Modules\Offer\Application\Actions\WithdrawOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| The on-hand mirror, and the buy box reading availability (ADR-048)
|--------------------------------------------------------------------------
|
| The seller keeps typing stock on the OFFER form — an owner decision — and
| Inventory keeps its own on-hand in step by consuming Offer's events BY
| CLASS-STRING. So the same number lives in two places, joined by an event rather
| than a shared row, and this file is what stops that drifting silently.
|
| It fires the REAL Offer actions rather than calling Inventory directly. That is
| the point: the subscription is by NAME, so a rename in Offer would break the
| mirror at runtime with nothing else noticing. These tests are the something.
|
| The buy-box half is a REGRESSION GUARD, not a behaviour change: with no Order
| module `reserved` is always 0, so availability equals the seller's declared
| stock and the buy box should behave exactly as it did before — except that it
| now goes through Inventory to find out.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A real seller with a live store and a published variant — everything
 * `CreateOfferAction` validates, so the events under test are the real ones.
 *
 * @return array{org: Organization, store: Store, variant: ProductVariant, product: Product}
 */
function offerableFixture(): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    return ['org' => $organization, 'store' => $store, 'variant' => $variant, 'product' => $product];
}

/**
 * @param array{org: Organization, store: Store, variant: ProductVariant, product: Product} $fixture
 */
function listOffer(array $fixture, int $stock = 10): \App\Modules\Offer\Domain\Models\Offer
{
    return app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $fixture['variant']->uuid,
        sellingOrgId: $fixture['org']->getKey(),
        sellingOrgUuid: $fixture['org']->uuid,
        storeUuid: $fixture['store']->uuid,
        priceMinor: 12_990,
        stockQuantity: $stock,
    ));
}

it('creates a stock pool when a seller lists an offer', function (): void {
    $fixture = offerableFixture();

    listOffer($fixture, 10);

    $item = StockItem::query()
        ->forSellingOrg($fixture['org']->uuid)
        ->forVariant($fixture['variant']->uuid)
        ->sole();

    // Mirrored blind: Inventory never imported Offer to learn any of this.
    expect($item->on_hand)->toBe(10)
        ->and($item->reserved)->toBe(0)
        ->and($item->product_uuid)->toBe($fixture['product']->uuid)
        ->and($item->selling_org_id)->toBe($fixture['org']->getKey());

    // And the units are accounted for in the ledger, not just in the projection.
    expect(StockMovement::query()->where('stock_item_id', $item->getKey())
        ->where('type', StockMovementType::SellerAdjustment->value)->count())->toBe(1);
});

it('moves on-hand when the seller edits stock on the offer form', function (): void {
    $fixture = offerableFixture();
    $offer = listOffer($fixture, 10);

    app(UpdateOfferStockAction::class)->run($offer, new UpdateOfferStockDTO(stockQuantity: 3));

    expect(app(InventoryQueryContract::class)->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))
        ->toBe(3);
});

it('zeroes on-hand when the offer is withdrawn, keeping the pool and its history', function (): void {
    $fixture = offerableFixture();
    $offer = listOffer($fixture, 10);

    app(WithdrawOfferAction::class)->run($offer);

    $item = StockItem::query()->forVariant($fixture['variant']->uuid)->sole();

    // The listing is gone; the ledger is evidence. A seller who re-lists
    // tomorrow finds their history rather than a fresh row.
    expect($item->on_hand)->toBe(0)
        ->and($item->available())->toBe(0)
        ->and(StockMovement::query()->where('stock_item_id', $item->getKey())->count())->toBe(2);
});

it('subscribes to the three Offer stock events by class-string', function (): void {
    // Subscribed by NAME because Inventory imports no module — so nothing but
    // this would notice if the wiring were dropped or an event renamed.
    foreach ([
        'App\Modules\Offer\Domain\Events\OfferCreated',
        'App\Modules\Offer\Domain\Events\OfferStockChanged',
        'App\Modules\Offer\Domain\Events\OfferWithdrawn',
    ] as $event) {
        expect(Event::hasListeners($event))->toBeTrue("no listener for {$event}");
    }
});

it('ignores a payload that does not carry what the mirror needs', function (): void {
    $listener = app(\App\Modules\Inventory\Application\Listeners\MirrorOfferStock::class);

    // Reached by name, so a wrong event class is a live possibility rather than
    // a compile error: dropping the update beats a fatal on somebody else's
    // event.
    $listener->onStockDeclared(new class {});
    $listener->onWithdrawn(new class {});

    expect(StockItem::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The buy box now reads Inventory
|--------------------------------------------------------------------------
*/

it('keeps the buy box behaving exactly as before — the parity this phase promised', function (): void {
    $fixture = offerableFixture();
    $offer = listOffer($fixture, 10);

    $featured = app(\App\Core\Domain\Contracts\OfferQueryContract::class)
        ->featuredOfferForProduct($fixture['product']->uuid);

    expect($featured)->not->toBeNull()
        ->and($featured['uuid'])->toBe($offer->uuid)
        ->and($featured['in_stock'])->toBeTrue();
});

it('drops an offer from the buy box when the seller’s stock reaches zero', function (): void {
    $fixture = offerableFixture();
    $offer = listOffer($fixture, 1);

    app(UpdateOfferStockAction::class)->run($offer, new UpdateOfferStockDTO(stockQuantity: 0));

    // Same outcome as before ADR-048 — reached through Inventory rather than
    // through the Offer's own column.
    expect(app(\App\Core\Domain\Contracts\OfferQueryContract::class)
        ->featuredOfferForProduct($fixture['product']->uuid))->toBeNull();
});

it('drops an offer from the buy box when every unit is RESERVED', function (): void {
    $fixture = offerableFixture();
    listOffer($fixture, 1);

    app(InventoryReservationContract::class)->reserve(
        $fixture['org']->uuid,
        $fixture['variant']->uuid,
        1,
        'checkout-1',
    );

    /*
     * THE CASE THAT WAS IMPOSSIBLE BEFORE THIS PHASE. The seller still declares
     * one in stock and `Offer.stock_quantity` still reads 1 — but it is promised
     * to somebody's checkout, so it is not for sale. No column on the Offer
     * could have expressed that, which is the whole reason the buy box now asks
     * Inventory.
     */
    expect(app(InventoryQueryContract::class)->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))
        ->toBe(1);

    expect(app(\App\Core\Domain\Contracts\OfferQueryContract::class)
        ->featuredOfferForProduct($fixture['product']->uuid))->toBeNull();
});

it('returns the offer to the buy box when the hold is released', function (): void {
    $fixture = offerableFixture();
    $offer = listOffer($fixture, 1);

    $reservations = app(InventoryReservationContract::class);
    $reservations->reserve($fixture['org']->uuid, $fixture['variant']->uuid, 1, 'checkout-1');
    $reservations->release('checkout-1');

    // An abandoned cart must not cost the seller a sale.
    expect(app(\App\Core\Domain\Contracts\OfferQueryContract::class)
        ->featuredOfferForProduct($fixture['product']->uuid)['uuid'])->toBe($offer->uuid);
});
