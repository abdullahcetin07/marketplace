<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;

/*
|--------------------------------------------------------------------------
| CatalogBrowse — the seller's "select a product to sell" port (§8.2)
|--------------------------------------------------------------------------
|
| The one Catalog change the Offer sprint makes: a read contract, no schema
| change. Exercised through the CORE CONTRACT, never the implementation class —
| that is how Offer sees it, and it is what keeps swapping the implementation
| (for an index-backed one, when the catalog is big enough to need it) a change
| of one container binding.
|
| The load-bearing assertion is the negative: an unpublished product must be
| invisible here. This port is the seller's discovery surface, and a draft
| reachable through it would make a moderation state sellable through the side
| door (§3.4).
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A published product filed under a leaf of a real two-level taxonomy.
 */
function browsableProduct(string $title, ?Category $category = null, ?Brand $brand = null): Product
{
    $category ??= Category::factory()->childOf(Category::factory()->create(['name_tr' => 'Giyim']))
        ->create(['name_tr' => 'Tişört']);

    return Product::factory()
        ->for($category, 'category')
        ->for($brand ?? Brand::factory()->create(['name' => 'Beko']))
        ->published()
        ->create(['title_tr' => $title]);
}

it('finds a published product by a word in its title', function (): void {
    browsableProduct('Pamuklu Tişört');
    browsableProduct('Deri Ceket');

    $result = app(CatalogBrowseContract::class)->searchPublishedProducts('tişört');

    expect($result['total'])->toBe(1)
        ->and($result['items'][0]['title'])->toBe('Pamuklu Tişört');
});

it('never surfaces a product that is not published', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();

    Product::factory()->for($category, 'category')->create(['title_tr' => 'Gizli Ürün']);
    browsableProduct('Açık Ürün', $category);

    $result = app(CatalogBrowseContract::class)->searchPublishedProducts('Ürün');

    // A seller must not be able to discover — let alone offer against — a
    // product no moderator has approved.
    expect($result['total'])->toBe(1)
        ->and(array_column($result['items'], 'title'))->toBe(['Açık Ürün']);
});

it('returns everything published when the query is empty', function (): void {
    browsableProduct('Bir Ürün');
    browsableProduct('Başka Ürün');

    // Browsing with no term is a legitimate way to shop the catalog; it must
    // not mean "match nothing".
    expect(app(CatalogBrowseContract::class)->searchPublishedProducts()['total'])->toBe(2);
});

it('filters by a category and includes its whole subtree', function (): void {
    $root = Category::factory()->create(['name_tr' => 'Giyim']);
    $leaf = Category::factory()->childOf($root)->create(['name_tr' => 'Tişört']);
    $elsewhere = Category::factory()->create(['name_tr' => 'Elektronik']);

    browsableProduct('Pamuklu Tişört', $leaf);
    browsableProduct('Buzdolabı', $elsewhere);

    $browse = app(CatalogBrowseContract::class);

    // Picking the DEPARTMENT finds what is filed beneath it — a seller thinks
    // "Giyim", not "Giyim > Üst Giyim > Tişört".
    expect($browse->searchPublishedProducts(categoryUuid: $root->uuid)['total'])->toBe(1)
        ->and($browse->searchPublishedProducts(categoryUuid: $leaf->uuid)['total'])->toBe(1)
        ->and($browse->searchPublishedProducts(categoryUuid: $elsewhere->uuid)['total'])->toBe(1);
});

it('filters by brand', function (): void {
    $beko = Brand::factory()->create(['name' => 'Beko']);
    $arcelik = Brand::factory()->create(['name' => 'Arçelik']);
    $category = Category::factory()->childOf(Category::factory()->create())->create();

    browsableProduct('Beko Buzdolabı', $category, $beko);
    browsableProduct('Arçelik Buzdolabı', $category, $arcelik);

    $result = app(CatalogBrowseContract::class)->searchPublishedProducts(brandUuid: $beko->uuid);

    expect($result['total'])->toBe(1)
        ->and($result['items'][0]['brand'])->toBe('Beko');
});

it('returns nothing — not everything — for a filter that names nothing', function (): void {
    browsableProduct('Pamuklu Tişört');

    $browse = app(CatalogBrowseContract::class);

    // The dangerous failure mode: an unknown filter silently dropped, so the
    // seller sees the whole catalog and believes it was filtered.
    expect($browse->searchPublishedProducts(categoryUuid: 'no-such-category')['total'])->toBe(0)
        ->and($browse->searchPublishedProducts(brandUuid: 'no-such-brand')['total'])->toBe(0);
});

it('paginates and caps the page size', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();

    foreach (range(1, 5) as $i) {
        browsableProduct("Ürün {$i}", $category);
    }

    $browse = app(CatalogBrowseContract::class);
    $first = $browse->searchPublishedProducts(perPage: 2);

    expect($first['total'])->toBe(5)
        ->and($first['items'])->toHaveCount(2)
        ->and($first['last_page'])->toBe(3)
        ->and($browse->searchPublishedProducts(page: 3, perPage: 2)['items'])->toHaveCount(1);

    // A caller cannot ask for the whole catalog in one request.
    expect($browse->searchPublishedProducts(perPage: 10_000)['per_page'])->toBe(100);
});

it('lists a product’s variants with a label a human recognises', function (): void {
    $colour = Attribute::factory()->variantDefining()->withValues(2)->create(['code' => 'renk']);
    $product = browsableProduct('Pamuklu Tişört');

    $variant = ProductVariant::factory()->for($product)->create(['sku' => 'TS-RED-M']);
    // The pivot carries the attribute too, so a value can be read back to its
    // axis without a join through `attribute_values`.
    $variant->attributeValues()->attach(
        $colour->values->first()->getKey(),
        ['attribute_id' => $colour->getKey()],
    );

    $variants = app(CatalogBrowseContract::class)->variantsForProduct($product->uuid);

    expect($variants)->toHaveCount(1)
        ->and($variants[0]['uuid'])->toBe($variant->uuid)
        ->and($variants[0]['sku'])->toBe('TS-RED-M')
        ->and($variants[0]['label'])->toBe($colour->values->first()->localized('label'));
});

it('falls back to the SKU for a product with no variant axes', function (): void {
    $product = browsableProduct('Tek Varyantlı Ürün');
    ProductVariant::factory()->for($product)->create(['sku' => 'SIMPLE-1', 'is_default' => true]);

    // ADR-039: a "simple" product is a one-variant product. It still needs a
    // label to render, and an empty string is not one.
    expect(app(CatalogBrowseContract::class)->variantsForProduct($product->uuid)[0]['label'])
        ->toBe('SIMPLE-1');
});

it('refuses to enumerate the variants of an unpublished product', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $draft = Product::factory()->for($category, 'category')->create();
    ProductVariant::factory()->for($draft)->create();

    // The parent is re-checked rather than trusting that the caller arrived
    // from a search result.
    expect(app(CatalogBrowseContract::class)->variantsForProduct($draft->uuid))->toBe([])
        ->and(app(CatalogBrowseContract::class)->variantsForProduct('no-such-product'))->toBe([]);
});
