<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Application\Actions\PauseOfferAction;
use App\Modules\Offer\Application\Actions\UpdateOfferStockAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Offer\Presentation\Requests\BuyBoxPricesRequest;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| The listing's prices, in one call (ADR-058, Storefront.md §1.2)
|--------------------------------------------------------------------------
|
| THE PRICE HALF OF THE COMPOSED READ. Catalog's cards carry no price (ADR-037);
| this supplies it for a whole page so a listing renders "₺X'den başlayan
| fiyatlarla" without one request per card.
|
| The property that matters most is AGREEMENT: this must return the same winner
| the product page's buy box shows. A shopper seeing one price on a listing and
| another after clicking would make every price on the site untrustworthy — so
| both go through the same `eligible()` rule (ADR-045) rather than each computing
| "cheapest" its own way.
|
| The second theme is that an absent product tells you NOTHING. Unknown, unsold
| and unpublished uuids all come back the same way — omitted — so this cannot be
| used to probe which product uuids exist.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A published product with one live offer.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{product: Product, offer: \App\Modules\Offer\Domain\Models\Offer, variant: ProductVariant, org: Organization}
 */
function pricedProduct(int $priceMinor = 12_000, int $stock = 10): array
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
        priceMinor: $priceMinor,
        stockQuantity: $stock,
    ));

    return ['product' => $product, 'offer' => $offer, 'variant' => $variant, 'org' => $organization];
}

it('returns a decimal-string price keyed by product, anonymously', function (): void {
    $fixture = pricedProduct(priceMinor: 12_990);

    $response = $this->postJson('/api/v1/offers/prices', [
        'product_ids' => [$fixture['product']->uuid],
    ])->assertOk();

    /*
     * MONEY AS A DECIMAL STRING (005 §28) — most clients parse a JSON number as a
     * float, and on a listing that is every price on the page.
     */
    $response->assertJsonPath('data.'.$fixture['product']->uuid.'.from_price', '129.90')
        ->assertJsonPath('data.'.$fixture['product']->uuid.'.in_stock', true);

    expect(json_encode($response->json()))->not->toContain('price_minor');
});

it('quotes the CHEAPEST seller — the same winner the product page shows', function (): void {
    $fixture = pricedProduct(priceMinor: 30_000);

    // A second merchant undercuts the first on the same product.
    $rival = Organization::factory()->create();
    $rivalStore = Store::factory()->create([
        'organization_id' => $rival->getKey(),
        'status' => StoreStatus::Active,
    ]);

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $fixture['variant']->uuid,
        sellingOrgId: $rival->getKey(),
        sellingOrgUuid: $rival->uuid,
        storeUuid: $rivalStore->uuid,
        priceMinor: 19_900,
        stockQuantity: 5,
    ));

    $listing = $this->postJson('/api/v1/offers/prices', [
        'product_ids' => [$fixture['product']->uuid],
    ])->assertOk()->json('data.'.$fixture['product']->uuid.'.from_price');

    $page = $this->getJson('/api/v1/products/'.$fixture['product']->uuid.'/offers')
        ->assertOk()
        ->json('data.featured.price');

    // THE ASSERTION THIS ENDPOINT EXISTS TO KEEP TRUE. One price on a listing and
    // another after clicking would make every price on the site untrustworthy.
    expect($listing)->toBe('199.00')->toBe($page);
});

it('omits a product whose only seller is paused or sold out', function (): void {
    $paused = pricedProduct();
    $soldOut = pricedProduct();
    $live = pricedProduct(priceMinor: 5_000);

    app(PauseOfferAction::class)->run($paused['offer'], 'Stok bekleniyor');
    app(UpdateOfferStockAction::class)->run($soldOut['offer'], new UpdateOfferStockDTO(stockQuantity: 0));

    $data = $this->postJson('/api/v1/offers/prices', [
        'product_ids' => [
            $paused['product']->uuid,
            $soldOut['product']->uuid,
            $live['product']->uuid,
        ],
    ])->assertOk()->json('data');

    /*
     * ABSENT, NOT NULL-PRICED. A caller iterating the result gets only things it
     * can render a price for — a "from ₺—" card is not a card.
     */
    expect($data)->toHaveCount(1)
        ->and($data)->toHaveKey($live['product']->uuid)
        ->and($data)->not->toHaveKey($paused['product']->uuid)
        ->and($data)->not->toHaveKey($soldOut['product']->uuid);
});

it('treats an unknown uuid exactly like an unsold one', function (): void {
    $fixture = pricedProduct();

    $data = $this->postJson('/api/v1/offers/prices', [
        'product_ids' => [$fixture['product']->uuid, (string) Str::uuid()],
    ])->assertOk()->json('data');

    // No leak: this endpoint cannot be used to probe which product uuids exist,
    // because "does not exist" and "nobody sells it" answer identically.
    expect($data)->toHaveCount(1)
        ->and($data)->toHaveKey($fixture['product']->uuid);
});

it('caps the list rather than letting one request price the catalogue', function (): void {
    $tooMany = array_map(static fn (): string => (string) Str::uuid(), range(1, BuyBoxPricesRequest::MAX_PRODUCTS + 1));

    // Uncapped, each entry costs a store-liveness and an availability check —
    // a denial-of-service written as a feature.
    $this->postJson('/api/v1/offers/prices', ['product_ids' => $tooMany])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('product_ids');
});

it('refuses a malformed list at the edge', function (): void {
    // A malformed list must not become a hundred pointless lookups.
    $this->postJson('/api/v1/offers/prices', ['product_ids' => ['not-a-uuid']])
        ->assertUnprocessable();

    $this->postJson('/api/v1/offers/prices', ['product_ids' => []])
        ->assertUnprocessable();

    $this->postJson('/api/v1/offers/prices', [])
        ->assertUnprocessable();
});

it('answers one product once, however often it is asked for', function (): void {
    $fixture = pricedProduct();

    $data = $this->postJson('/api/v1/offers/prices', [
        'product_ids' => array_fill(0, 5, $fixture['product']->uuid),
    ])->assertOk()->json('data');

    // Keyed by uuid, so a duplicated input collapses rather than multiplying the
    // work — a client zipping this against its cards does not care about order.
    expect($data)->toHaveCount(1);
});

it('is open to anyone, because a price is not a secret', function (): void {
    $fixture = pricedProduct();

    // No token, no session. Requiring a login to see what something costs would
    // be the end of the shop.
    $this->postJson('/api/v1/offers/prices', ['product_ids' => [$fixture['product']->uuid]])
        ->assertOk();
});

it('never returns an internal id or a seller', function (): void {
    $fixture = pricedProduct();

    $entry = $this->postJson('/api/v1/offers/prices', [
        'product_ids' => [$fixture['product']->uuid],
    ])->assertOk()->json('data.'.$fixture['product']->uuid);

    /*
     * A LISTING NEEDS A PRICE, NOT A MERCHANT. Naming the winning seller here
     * would tell a competitor who is cheapest on every product in one request —
     * and the product page already names them, one product at a time, which is
     * the pace at which that information is a feature rather than a scrape.
     *
     * `seller_count` DOES NOT BREAK THAT and is the reason to say so here: how
     * MANY merchants compete is a shopper's decision input ("N satıcı"), while
     * WHICH ONE wins is the thing worth scraping. A count identifies nobody.
     */
    expect(array_keys($entry))->toBe(['from_price', 'list_price', 'currency', 'in_stock', 'seller_count']);
});

it('counts merchants rather than offers, and quotes the winner’s struck price', function (): void {
    $fixture = pricedProduct(priceMinor: 30_000);

    // A SECOND VARIANT FROM THE SAME SELLER. Offers are per variant (ADR-042), so
    // this is a second eligible row and NOT a second choice for the buyer — the
    // case a naive count gets wrong.
    $sibling = ProductVariant::factory()->for($fixture['product'])->create();

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $sibling->uuid,
        sellingOrgId: $fixture['org']->getKey(),
        sellingOrgUuid: $fixture['org']->uuid,
        storeUuid: $fixture['offer']->store_uuid,
        priceMinor: 32_000,
        stockQuantity: 4,
    ));

    $entry = $this->postJson('/api/v1/offers/prices', [
        'product_ids' => [$fixture['product']->uuid],
    ])->assertOk()->json('data.'.$fixture['product']->uuid);

    expect($entry['seller_count'])->toBe(1);

    // Now a genuine rival, cheaper, and declaring a "was" price.
    $rival = Organization::factory()->create();
    $rivalStore = Store::factory()->create([
        'organization_id' => $rival->getKey(),
        'status' => StoreStatus::Active,
    ]);

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $fixture['variant']->uuid,
        sellingOrgId: $rival->getKey(),
        sellingOrgUuid: $rival->uuid,
        storeUuid: $rivalStore->uuid,
        priceMinor: 19_900,
        stockQuantity: 5,
        listPriceMinor: 34_900,
    ));

    $entry = $this->postJson('/api/v1/offers/prices', [
        'product_ids' => [$fixture['product']->uuid],
    ])->assertOk()->json('data.'.$fixture['product']->uuid);

    /*
     * THE LIST PRICE BELONGS TO THE WINNER, not to the product. The first seller
     * declared none; the cheapest one declared ₺349, so that is what a card strikes
     * through — one merchant's claim about their own discount, next to their own
     * price, rather than two merchants' numbers put side by side.
     */
    expect($entry['seller_count'])->toBe(2)
        ->and($entry['from_price'])->toBe('199.00')
        ->and($entry['list_price'])->toBe('349.00');
});

it('leaves list_price null when the winning seller declared none', function (): void {
    $fixture = pricedProduct(priceMinor: 12_000);

    $entry = $this->postJson('/api/v1/offers/prices', [
        'product_ids' => [$fixture['product']->uuid],
    ])->assertOk()->json('data.'.$fixture['product']->uuid);

    // Null rather than the price repeated: a card must strike a price through only
    // when a seller actually claimed a discount, and "₺120 ~~₺120~~" is worse than
    // no badge at all.
    expect($entry['list_price'])->toBeNull();
});
