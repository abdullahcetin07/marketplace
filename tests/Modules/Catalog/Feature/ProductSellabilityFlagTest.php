<?php

declare(strict_types=1);

use App\Modules\Catalog\Application\Services\ProductSellability;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Application\Actions\UpdateOfferStockAction;
use App\Modules\Offer\Application\Actions\WithdrawOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| products.is_sellable — the denormalised wall (ADR-079)
|--------------------------------------------------------------------------
|
| The browse used to collect every sellable product uuid and hand them to a
| `whereIn`: 7,025 of them on the live catalogue, built from 9,510 round trips,
| 22 seconds per request and 504s that reached shoppers as "Application error".
|
| The flag is a CACHE of what Offer, Store and Inventory say. These tests care
| about the two things a cache must prove: that it follows the truth when the
| truth moves, and that it can be rebuilt from scratch when it has drifted.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A published product with one live, in-stock offer.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{product: Product, offer: Offer}
 */
function sellabilityFixture(int $stock = 10): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    $offer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: 12_000,
        stockQuantity: $stock,
    ));

    return ['product' => $product, 'offer' => $offer];
}

it('marks a product sellable the moment somebody offers it', function (): void {
    $fixture = sellabilityFixture();

    // `OfferCreated` is heard by class-string — Catalog imports no Offer class.
    expect($fixture['product']->fresh()->is_sellable)->toBeTrue();
});

it('clears the flag when the last unit goes, and sets it again on restock', function (): void {
    $fixture = sellabilityFixture();

    app(UpdateOfferStockAction::class)->run($fixture['offer'], new UpdateOfferStockDTO(stockQuantity: 0));

    expect($fixture['product']->fresh()->is_sellable)->toBeFalse();

    app(UpdateOfferStockAction::class)->run($fixture['offer']->fresh(), new UpdateOfferStockDTO(stockQuantity: 4));

    /*
     * **BOTH DIRECTIONS, BECAUSE A ONE-WAY CACHE IS A DISAPPEARING CATALOGUE.** A
     * flag that only ever cleared would take a restocked product off the
     * storefront permanently, and nothing would report it: the product is right in
     * the table and invisible to buyers.
     */
    expect($fixture['product']->fresh()->is_sellable)->toBeTrue();
});

it('keeps a product sellable while another merchant still sells it', function (): void {
    $first = sellabilityFixture();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);
    $variant = ProductVariant::factory()->for($first['product'])->create();

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: 15_000,
        stockQuantity: 3,
    ));

    app(WithdrawOfferAction::class)->run($first['offer']);

    /*
     * **AN EVENT SAYS "LOOK AGAIN", NOT "IT IS GONE".** The catalogue is shared
     * (ADR-037): one merchant withdrawing tells you nothing about the others, so
     * the listener recomputes rather than infers.
     */
    expect($first['product']->fresh()->is_sellable)->toBeTrue();
});

it('rebuilds the flag from scratch, which is how drift heals', function (): void {
    $sellable = sellabilityFixture();
    $notSellable = sellabilityFixture(stock: 0);

    // Corrupt both, the way a fix script or a missed event would.
    Product::query()->update(['is_sellable' => false]);
    $notSellable['product']->forceFill(['is_sellable' => true])->save();

    app(ProductSellability::class)->rebuild();

    expect($sellable['product']->fresh()->is_sellable)->toBeTrue()
        ->and($notSellable['product']->fresh()->is_sellable)->toBeFalse();
});

it('keeps an unsellable product out of the browse, flag or no flag', function (): void {
    $fixture = sellabilityFixture();

    $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data');

    app(UpdateOfferStockAction::class)->run($fixture['offer'], new UpdateOfferStockDTO(stockQuantity: 0));

    // THE CORRECTNESS THE SPEED WAS TRADED AGAINST: a sold-out product must not
    // reach a buyer, and the denormalisation may not change that answer.
    $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
});

it('keeps an unpublished product out of the browse even while it is sellable', function (): void {
    $fixture = sellabilityFixture();

    $fixture['product']->forceFill(['status' => ProductStatus::Draft])->save();

    /*
     * The flag is about OFFERS, not about moderation: `status` is a separate
     * filter and the index is composite over both. A product can be perfectly
     * sellable and still not be published.
     */
    expect($fixture['product']->fresh()->is_sellable)->toBeTrue();

    $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
});
