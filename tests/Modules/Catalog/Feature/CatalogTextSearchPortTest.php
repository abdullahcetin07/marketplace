<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;

/*
|--------------------------------------------------------------------------
| Catalog — the free-text port a seller's panel searches through
|--------------------------------------------------------------------------
|
| Offer and Inventory hold uuids and nothing else about the catalogue, so their
| tables cannot search themselves. This port is how a merchant's search box
| turns "tisort", a barcode or their own SKU into rows of theirs.
|
| What is pinned here: titles fold (Turkish), codes are EXACT, a term that folds
| to nothing does not return the whole catalogue, and an unpublished product is
| still findable — its offers exist and the seller is looking for them.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

function searchablePortProduct(string $title, ?string $gtin = null): Product
{
    $category = Category::factory()->childOf(Category::factory()->create())->create();

    return Product::factory()->for($category, 'category')->published()
        ->create(['title_tr' => $title, 'gtin' => $gtin]);
}

/**
 * @return array{products: array<int, string>, variants: array<int, string>}
 */
function portSearch(string $term, int $limit = 300): array
{
    return app(CatalogQueryContract::class)->uuidsMatchingText($term, $limit);
}

it('finds a product by a folded title fragment', function (): void {
    $product = searchablePortProduct('Pamuklu Tişört');
    $other = searchablePortProduct('Deri Ayakkabı');

    // Typed without the diacritic, as a keyboard in a hurry produces it.
    $result = portSearch('tisort');

    expect($result['products'])->toContain($product->uuid)
        ->and($result['products'])->not->toContain($other->uuid);
});

it('matches a barcode exactly, never fuzzily', function (): void {
    $product = searchablePortProduct('Güneş Kremi', '8690632012345');

    expect(portSearch('8690632012345')['products'])->toContain($product->uuid)
        // One digit out is a DIFFERENT product, not a near miss.
        ->and(portSearch('8690632012346')['products'])->not->toContain($product->uuid);
});

it('finds a variant by its SKU or its own barcode', function (): void {
    $product = searchablePortProduct('Şampuan');
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'RF-SHMP-001',
        'barcode' => '8690000000017',
    ]);

    expect(portSearch('RF-SHMP-001')['variants'])->toBe([$variant->uuid])
        ->and(portSearch('8690000000017')['variants'])->toBe([$variant->uuid])
        ->and(portSearch('RF-SHMP-00')['variants'])->toBe([]);
});

it('still finds a product the catalogue no longer publishes', function (): void {
    // The seller's offer outlives publication — paused, not deleted — and that
    // paused row is exactly the one they came to look at.
    $product = searchablePortProduct('Arşivlenmiş Ürün');
    $product->forceFill(['status' => ProductStatus::Archived])->save();

    expect(portSearch('arsivlenmis')['products'])->toContain($product->uuid);
});

it('answers an empty or punctuation-only term with nothing', function (string $term): void {
    searchablePortProduct('Herhangi Bir Ürün');

    // A term that folds to no tokens must not degenerate into LIKE '%%' and
    // hand back the whole catalogue.
    expect(portSearch($term))->toBe(['products' => [], 'variants' => []]);
})->with(['empty' => '', 'spaces' => '   ', 'punctuation' => '-']);

it('caps how many uuids it will hand back', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();

    Product::factory()->for($category, 'category')->published()->count(5)
        ->sequence(fn ($sequence): array => ['title_tr' => 'Kapak Testi '.$sequence->index])
        ->create();

    expect(portSearch('kapak testi', limit: 2)['products'])->toHaveCount(2);
});
