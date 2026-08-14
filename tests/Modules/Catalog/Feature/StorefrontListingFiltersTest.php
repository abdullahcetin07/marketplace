<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Listing filters: price range + brand facet (ADR-080)
|--------------------------------------------------------------------------
|
| Two filters and the data the UI needs to offer them. The price lives in Offer
| and reaches Catalog through the Core contract; the brand facet is Catalog's own
| grouped count over the indexed `is_sellable` path (ADR-079).
|
| The rule worth testing hardest is the faceting one: facets are computed over the
| query MINUS the brand and price the shopper already picked, so a choice never
| hides its own alternatives.
|
*/

beforeEach(function (): void {
    $this->seedAll();

    // Facets are cached per (category, q) scope; a shared cache would serve the
    // previous test's answer.
    Cache::flush();
});

/**
 * A published, sellable product at a given price.
 *
 * Named for this file: Pest shares ONE global function namespace.
 */
function filterableProduct(string $title, int $priceMinor, ?Brand $brand = null, ?Category $category = null): Product
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category ??= Category::factory()->childOf(Category::factory()->create())->create();

    $product = Product::factory()->for($category, 'category')->published()->create([
        'title_tr' => $title,
        'title_en' => $title,
        'brand_id' => $brand?->getKey(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create();

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: $priceMinor,
        stockQuantity: 5,
    ));

    return $product;
}

it('narrows the grid and the total to the requested price range', function (): void {
    $cheap = filterableProduct('Ucuz', 4_990);
    $mid = filterableProduct('Orta', 10_000);
    filterableProduct('Pahalı', 50_000);

    $response = $this->getJson('/api/v1/products?price_min=49.90&price_max=100.00')->assertOk();

    /*
     * **BOTH BOUNDS INCLUSIVE.** A shopper who types 49,90–100,00 means to see the
     * thing that costs exactly 49,90, and a range control's handles sit ON the
     * numbers it shows.
     */
    /** @var array<int, array<string, mixed>> $cards */
    $cards = $response->json('data');

    expect($response->json('meta.total'))->toBe(2)
        ->and(array_column($cards, 'id'))
        ->toEqualCanonicalizing([$cheap->uuid, $mid->uuid]);
});

it('accepts a Turkish decimal comma and ignores a bound it cannot read', function (): void {
    filterableProduct('Ucuz', 4_990);
    filterableProduct('Pahalı', 50_000);

    $this->getJson('/api/v1/products?price_max=100,00')->assertOk()->assertJsonPath('meta.total', 1);

    /*
     * **AN UNREADABLE BOUND IS NO BOUND, NOT A ZERO.** `price_min=abc` filtering to
     * "at least ₺0.00" is a filter nobody asked for; ignoring it shows the listing
     * they expected.
     */
    $this->getJson('/api/v1/products?price_min=abc')->assertOk()->assertJsonPath('meta.total', 2);
});

it('lists the brands in scope with their counts, commonest first', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $popular = Brand::factory()->create(['name' => 'Çok Ürünlü']);
    $rare = Brand::factory()->create(['name' => 'Tek Ürünlü']);

    filterableProduct('A', 10_000, $popular, $category);
    filterableProduct('B', 20_000, $popular, $category);
    filterableProduct('C', 30_000, $rare, $category);

    // A brand nobody sells IN THIS CATEGORY must not be offered as a filter.
    filterableProduct('D', 10_000, Brand::factory()->create(['name' => 'Başka Kategori']));

    $facets = $this->getJson('/api/v1/products?category='.$category->uuid)
        ->assertOk()
        ->json('meta.facets.brands');

    expect($facets)->toHaveCount(2)
        ->and($facets[0]['name'])->toBe('Çok Ürünlü')
        ->and($facets[0]['count'])->toBe(2)
        ->and($facets[1]['count'])->toBe(1);
});

it('keeps every brand in the facet after one of them is applied', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $chosen = Brand::factory()->create(['name' => 'Seçilen']);
    $other = Brand::factory()->create(['name' => 'Diğeri']);

    filterableProduct('A', 10_000, $chosen, $category);
    filterableProduct('B', 20_000, $other, $category);

    $response = $this->getJson('/api/v1/products?category='.$category->uuid.'&brand='.$chosen->uuid)->assertOk();

    /*
     * **A FACET DOES NOT HIDE ITS OWN SIBLINGS.** Collapsing the brand list to the
     * brand already picked makes the browser's back button the only way to switch
     * — the grid narrows, the choices do not.
     */
    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('meta.facets.brands'))->toHaveCount(2);
});

it('reports the price span of the scope, and does not collapse it to the filtered subset', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();

    filterableProduct('Ucuz', 4_990, category: $category);
    filterableProduct('Orta', 10_000, category: $category);
    filterableProduct('Pahalı', 50_000, category: $category);

    $unfiltered = $this->getJson('/api/v1/products?category='.$category->uuid)->assertOk();

    expect($unfiltered->json('meta.facets.price'))->toBe(['min' => '49.90', 'max' => '500.00']);

    $filtered = $this->getJson('/api/v1/products?category='.$category->uuid.'&price_max=100.00')->assertOk();

    /*
     * The bounds are the range control's own scale. Shrinking them to what the
     * shopper has already selected leaves the control unable to widen again.
     */
    expect($filtered->json('meta.total'))->toBe(2)
        ->and($filtered->json('meta.facets.price'))->toBe(['min' => '49.90', 'max' => '500.00']);
});

it('composes category, brand and price', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $brand = Brand::factory()->create();

    $wanted = filterableProduct('İstenen', 10_000, $brand, $category);
    filterableProduct('Aynı marka, pahalı', 90_000, $brand, $category);
    filterableProduct('Aynı fiyat, başka marka', 10_000, Brand::factory()->create(), $category);
    filterableProduct('Başka kategori', 10_000, $brand);

    $response = $this->getJson(
        '/api/v1/products?category='.$category->uuid.'&brand='.$brand->uuid.'&price_min=50.00&price_max=200.00',
    )->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.id'))->toBe($wanted->uuid);
});

it('excludes a product nobody can buy from both the grid and the facets', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $brand = Brand::factory()->create();

    filterableProduct('Satılabilir', 10_000, $brand, $category);

    // In the catalogue, correct, complete — and nobody sells it, so it has no
    // price to filter on and no business in a brand count.
    $orphanBrand = Brand::factory()->create(['name' => 'Satıcısız Marka']);
    $orphan = Product::factory()->for($category, 'category')->published()->create(['brand_id' => $orphanBrand->getKey()]);
    ProductVariant::factory()->for($orphan)->create();

    $response = $this->getJson('/api/v1/products?category='.$category->uuid)->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('meta.facets.brands'))->toHaveCount(1)
        ->and($response->json('meta.facets.brands.0.name'))->toBe($brand->name);
});
