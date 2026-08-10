<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Models\Seller;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource;
use App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource\Pages\CreateOffer;
use App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource\Pages\EditOffer;
use App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource\Pages\ListOffers;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Seller panel — "Tekliflerim" and the catalog-first create flow
|--------------------------------------------------------------------------
|
| Three things are worth pinning:
|
|  1. THE TENANCY WALL (ADR-030). A second seller's offers are not in the
|     query. A failure here is a cross-tenant leak, not a UI bug.
|  2. The create flow reaches the catalog through the Core contract and ends at
|     `CreateOfferAction` — so the row, the events and the audit trail come out
|     identical to the API's, and money crosses the boundary as minor units.
|  3. The pickers narrow, they do not authorise: a store belonging to a company
|     the seller does not manage is absent from the options AND refused by the
|     action.
|
| The panel is set explicitly because a Livewire test has no panel middleware to
| do it.
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * A seller who can actually list: a company they manage, a live store, and a
 * published product with one variant.
 *
 * @return array{seller: Seller, org: Organization, store: Store, product: Product, variant: ProductVariant}
 */
function sellerReadyToList(OrganizationRole $role = OrganizationRole::Owner): array
{
    /** @var Seller $seller */
    $seller = Seller::factory()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role($role)
        ->create(['user_id' => $seller->getKey()]);

    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()
        ->create(['title_tr' => 'Pamuklu Tişört']);
    // The SKU is globally unique (Catalog §3.3), so it is left to the factory —
    // two fixtures in one test would collide on a hard-coded one.
    $variant = ProductVariant::factory()->for($product)->create();

    return [
        'seller' => $seller,
        'org' => $organization,
        'store' => $store,
        'product' => $product,
        'variant' => $variant,
    ];
}

it('lists only the acting seller’s own offers', function (): void {
    $fixture = sellerReadyToList();
    $this->actingAsSeller($fixture['seller']);

    $mine = Offer::factory()
        ->forOrganization($fixture['org']->getKey(), $fixture['org']->uuid)
        ->forStore($fixture['store']->uuid)
        ->forVariant($fixture['variant']->uuid, $fixture['product']->uuid)
        ->create();

    $theirs = Offer::factory()->create();

    Livewire::test(ListOffers::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('creates an offer from the catalog, converting money at the boundary', function (): void {
    $fixture = sellerReadyToList();
    $this->actingAsSeller($fixture['seller']);

    Livewire::test(CreateOffer::class)
        ->fillForm([
            'product_uuid' => $fixture['product']->uuid,
            'variant_uuid' => $fixture['variant']->uuid,
            'store_uuid' => $fixture['store']->uuid,
            'price' => '129.90',
            'list_price' => '199.90',
            'stock_quantity' => 7,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $offer = Offer::query()->where('variant_uuid', $fixture['variant']->uuid)->sole();

    // The seller typed decimals; the row holds integer minor units.
    expect($offer->price_minor)->toBe(12_990)
        ->and($offer->list_price_minor)->toBe(19_990)
        ->and($offer->stock_quantity)->toBe(7)
        ->and($offer->status)->toBe(OfferStatus::Active)
        // Both halves of the ADR-040 pair, derived from the chosen store rather
        // than taken from the payload.
        ->and($offer->selling_org_id)->toBe($fixture['org']->getKey())
        ->and($offer->selling_org_uuid)->toBe($fixture['org']->uuid)
        ->and($offer->product_uuid)->toBe($fixture['product']->uuid);
});

it('offers only stores the seller may actually list under', function (): void {
    $mine = sellerReadyToList();
    $theirs = sellerReadyToList();

    $this->actingAsSeller($mine['seller']);

    $options = OfferResource::sellableStores();

    expect($options)->toHaveKey($mine['store']->uuid)
        ->and($options)->not->toHaveKey($theirs['store']->uuid);
});

it('offers no store to a member without the management capability', function (): void {
    $fixture = sellerReadyToList(OrganizationRole::Viewer);
    $this->actingAsSeller($fixture['seller']);

    // A Viewer sees an empty picker rather than a form that fails on submit.
    expect(OfferResource::sellableStores())->toBe([])
        ->and(OfferResource::canCreate())->toBeFalse();
});

it('offers no store when the company’s storefront is not live', function (): void {
    $fixture = sellerReadyToList();
    $fixture['store']->forceFill(['status' => StoreStatus::Suspended])->save();

    $this->actingAsSeller($fixture['seller']);

    // "No store, no offer" (§3.4) reaches the seller as an empty picker.
    expect(OfferResource::sellableStores())->toBe([]);
});

it('re-prices and restocks through the actions, and only when something changed', function (): void {
    $fixture = sellerReadyToList();
    $this->actingAsSeller($fixture['seller']);

    $offer = Offer::factory()
        ->forOrganization($fixture['org']->getKey(), $fixture['org']->uuid)
        ->forStore($fixture['store']->uuid)
        ->forVariant($fixture['variant']->uuid, $fixture['product']->uuid)
        ->priced(12_990)
        ->create(['stock_quantity' => 3]);

    Livewire::test(EditOffer::class, ['record' => $offer->getRouteKey()])
        ->fillForm(['price' => '99.90', 'stock_quantity' => 12, 'list_price' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($offer->fresh()->price_minor)->toBe(9_990)
        ->and($offer->fresh()->stock_quantity)->toBe(12);
});

it('pauses, resumes and withdraws from the listing', function (): void {
    $fixture = sellerReadyToList();
    $this->actingAsSeller($fixture['seller']);

    $offer = Offer::factory()
        ->forOrganization($fixture['org']->getKey(), $fixture['org']->uuid)
        ->forStore($fixture['store']->uuid)
        ->forVariant($fixture['variant']->uuid, $fixture['product']->uuid)
        ->create();

    Livewire::test(ListOffers::class)->callTableAction('pause', $offer, ['reason' => 'Stok bekleniyor']);
    expect($offer->fresh()->status)->toBe(OfferStatus::Paused);

    Livewire::test(ListOffers::class)->callTableAction('resume', $offer->fresh());
    expect($offer->fresh()->status)->toBe(OfferStatus::Active);

    Livewire::test(ListOffers::class)->callTableAction('withdraw', $offer->fresh(), ['reason' => 'Artık satmıyorum']);
    expect(Offer::query()->whereKey($offer->getKey())->exists())->toBeFalse();
});

it('never offers a seller the admin’s suspension lever', function (): void {
    $fixture = sellerReadyToList();
    $this->actingAsSeller($fixture['seller']);

    Offer::factory()
        ->forOrganization($fixture['org']->getKey(), $fixture['org']->uuid)
        ->forStore($fixture['store']->uuid)
        ->forVariant($fixture['variant']->uuid, $fixture['product']->uuid)
        ->create();

    Livewire::test(ListOffers::class)
        ->assertTableActionDoesNotExist('suspend')
        ->assertTableActionDoesNotExist('reinstate');
});

it('shows the seller where they stand and what to beat', function (): void {
    $fixture = sellerReadyToList();
    $this->actingAsSeller($fixture['seller']);

    $mine = Offer::factory()
        ->forOrganization($fixture['org']->getKey(), $fixture['org']->uuid)
        ->forStore($fixture['store']->uuid)
        ->forVariant($fixture['variant']->uuid, $fixture['product']->uuid)
        ->priced(15_990)
        ->create();

    // A competitor on the same variant, cheaper, from a live store of their own.
    Offer::factory()
        ->forOrganization(999, 'rakip-org')
        ->forStore(Store::factory()->create(['status' => StoreStatus::Active])->uuid)
        ->forVariant($fixture['variant']->uuid, $fixture['product']->uuid)
        ->priced(12_990)
        ->create();

    // The seller sees only their own row (the tenancy wall) but is told the
    // truth about the competition on it — second of two, and the price to beat.
    Livewire::test(ListOffers::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertTableColumnStateSet('buy_box_rank', '2 / 2', $mine)
        ->assertTableColumnStateSet('buy_box_price', money(12_990, $mine->currency), $mine);
});

it('shows no rank for an offer that is not competing', function (): void {
    $fixture = sellerReadyToList();
    $this->actingAsSeller($fixture['seller']);

    $soldOut = Offer::factory()
        ->forOrganization($fixture['org']->getKey(), $fixture['org']->uuid)
        ->forStore($fixture['store']->uuid)
        ->forVariant($fixture['variant']->uuid, $fixture['product']->uuid)
        ->outOfStock()
        ->create();

    // "—", not a number: what this seller needs is to restock, not to re-price.
    Livewire::test(ListOffers::class)
        ->assertTableColumnStateSet('buy_box_rank', '—', $soldOut);
});

it('never lets a seller delete an offer', function (): void {
    $offer = Offer::factory()->create();

    // A future order line references the offer it was bought from.
    expect(OfferResource::canDelete($offer))->toBeFalse()
        ->and(OfferResource::canDeleteAny())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The number the seller reads is the one the shop uses
|--------------------------------------------------------------------------
|
| **THE BUG THIS PINS.** `stock_quantity` is what the seller TYPED and it never
| decrements — selling moves Inventory's `on_hand`, and nothing writes back
| (ADR-048). So an offer that sold all five units showed "Stok 5" on the seller's
| own screen while the buy box had already dropped it for `available = 0`. The
| seller's page and the shop disagreed, and only the shop was right.
|
*/

it('shows the LIVE sellable count, not the stale declared number', function (): void {
    $fixture = sellerReadyToList();
    $this->actingAsSeller($fixture['seller']);

    $offer = Offer::factory()
        ->forOrganization($fixture['org']->getKey(), $fixture['org']->uuid)
        ->forStore($fixture['store']->uuid)
        ->forVariant($fixture['variant']->uuid, $fixture['product']->uuid)
        ->create(['stock_quantity' => 5]);

    // The mirror gave Inventory five (ADR-048), and then all five sold: `on_hand`
    // is the number that moved, and it moved WITHOUT touching the offer.
    $stock = StockItem::query()
        ->where('variant_uuid', $fixture['variant']->uuid)
        ->where('selling_org_uuid', $fixture['org']->uuid)
        ->firstOrFail();

    $stock->forceFill(['on_hand' => 0, 'reserved' => 0])->save();

    expect($offer->fresh()->stock_quantity)->toBe(5)
        ->and(app(InventoryQueryContract::class)
            ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(0);

    /*
     * **BOTH NUMBERS ON THE PAGE, AND THE TRUE ONE READS "Tükendi".** The declared
     * column stays because it is the field the seller can actually change; seeing
     * the two side by side is what makes the gap legible — "I said 5, nothing is
     * sellable, so restock".
     */
    Livewire::test(ListOffers::class)
        ->assertCanSeeTableRecords([$offer])
        ->assertSee(__('offer.stock.sold_out'));
});

it('shows a real sellable count when there is stock', function (): void {
    $fixture = sellerReadyToList();
    $this->actingAsSeller($fixture['seller']);

    $offer = Offer::factory()
        ->forOrganization($fixture['org']->getKey(), $fixture['org']->uuid)
        ->forStore($fixture['store']->uuid)
        ->forVariant($fixture['variant']->uuid, $fixture['product']->uuid)
        ->create(['stock_quantity' => 9]);

    $stock = StockItem::query()
        ->where('variant_uuid', $fixture['variant']->uuid)
        ->where('selling_org_uuid', $fixture['org']->uuid)
        ->firstOrFail();

    // Two spoken for by an in-flight checkout: `available = on_hand − reserved`,
    // which is the number a buyer can still take and therefore the number the
    // seller should see.
    $stock->forceFill(['on_hand' => 9, 'reserved' => 2])->save();

    Livewire::test(ListOffers::class)
        ->assertCanSeeTableRecords([$offer])
        ->assertSee('7')
        ->assertDontSee(__('offer.stock.sold_out'));
});
