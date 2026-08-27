<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Support\TurkishFold;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Turkish search: what people type must find what the catalogue stores
|--------------------------------------------------------------------------
|
| Measured on the live site before this: `güneş` returned 343 products and
| `gunes` returned NONE. Most people type without diacritics — on a phone
| keyboard almost everyone does — so the most common shape of the most common
| query answered "0 sonuç".
|
| The cause was a docblock that said `ILIKE` "folds Turkish correctly" and code
| that believed it. `ILIKE` folds CASE. Both sides now go through `TurkishFold`:
| the haystack once on write into `products.search_text`, the needle at query
| time.
|
| Every test below uses TWO products at least, because a single-row fixture
| cannot show the difference between "found the right one" and "returned
| everything".
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A published, sellable product — named for this file, because Pest shares one
 * global function namespace.
 */
function turkishSearchProduct(
    string $title,
    ?Brand $brand = null,
    ?string $gtin = null,
): Product {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();

    $product = Product::factory()->for($category, 'category')->published()->create([
        'title_tr' => $title,
        'title_en' => $title,
        'brand_id' => $brand?->getKey(),
        'gtin' => $gtin,
    ]);

    $variant = ProductVariant::factory()->for($product)->create();

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: 12_000,
        stockQuantity: 5,
    ));

    return $product->refresh();
}

/** The buyer listing's URL for a search term. */
function searchUrl(string $query): string
{
    return '/api/v1/products?q='.urlencode($query);
}

/**
 * The titles a listing response returned.
 *
 * Takes the RESPONSE rather than the test case: `$this` inside a Pest closure
 * is a union type static analysis will not call `getJson()` on, so the request
 * stays inline where Pest binds it properly.
 *
 * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
 *
 * @return array<int, string>
 */
function titlesIn(TestResponse $response): array
{
    $response->assertOk();

    return array_column($response->json('data'), 'title');
}

it('finds a diacritic title from an ASCII query', function (): void {
    turkishSearchProduct('Güneş Kremi SPF 50');
    turkishSearchProduct('Emaye Kupa');

    expect(titlesIn($this->getJson(searchUrl('gunes'))))->toBe(['Güneş Kremi SPF 50'])
        ->and(titlesIn($this->getJson(searchUrl('gunes'))))->toBe(titlesIn($this->getJson(searchUrl('güneş'))));
});

it('treats every casing and diacritic spelling as one query', function (): void {
    turkishSearchProduct('Şampuan Kepek Karşıtı');
    turkishSearchProduct('Emaye Kupa');

    $expected = ['Şampuan Kepek Karşıtı'];

    expect(titlesIn($this->getJson(searchUrl('şampuan'))))->toBe($expected)
        ->and(titlesIn($this->getJson(searchUrl('sampuan'))))->toBe($expected)
        ->and(titlesIn($this->getJson(searchUrl('ŞAMPUAN'))))->toBe($expected)
        ->and(titlesIn($this->getJson(searchUrl('Sampuan'))))->toBe($expected);
});

it('folds the dotless ı onto i', function (): void {
    /*
    | Turkish has two i's and Unicode keeps them apart: `İ` lowercases to i plus
    | a combining dot, `ı` lowercases to itself. A shopper typing `urıage` on a
    | Turkish keyboard must still find Uriage — `mb_strtolower` alone does not
    | get there.
    */
    turkishSearchProduct('Uriage Bariederm Krem');
    turkishSearchProduct('Emaye Kupa');

    expect(titlesIn($this->getJson(searchUrl('urıage'))))->toBe(['Uriage Bariederm Krem'])
        ->and(titlesIn($this->getJson(searchUrl('URIAGE'))))->toBe(['Uriage Bariederm Krem'])
        ->and(TurkishFold::fold('İSTANBUL'))->toBe('istanbul');
});

it('matches tokens in any order, not one adjacent substring', function (): void {
    /*
    | `%leke serum%` demanded the words be adjacent and in that order, which is
    | not how anybody searches.
    */
    turkishSearchProduct('Serum Leke Karşıtı C Vitamini');
    turkishSearchProduct('Nemlendirici Krem');

    expect(titlesIn($this->getJson(searchUrl('leke serum'))))->toBe(['Serum Leke Karşıtı C Vitamini'])
        ->and(titlesIn($this->getJson(searchUrl('serum leke'))))->toBe(['Serum Leke Karşıtı C Vitamini']);
});

it('finds a product by a brand that its title never mentions', function (): void {
    /*
    | Brand-only search used to work by ACCIDENT — most titles happen to start
    | with the brand. This title does not mention it at all.
    */
    $brand = Brand::factory()->create(['name' => 'Avène']);

    turkishSearchProduct('Termal Su Spreyi', brand: $brand);
    turkishSearchProduct('Emaye Kupa');

    expect(titlesIn($this->getJson(searchUrl('avene'))))->toBe(['Termal Su Spreyi'])
        ->and(titlesIn($this->getJson(searchUrl('avène'))))->toBe(['Termal Su Spreyi']);
});

it('keeps a barcode an exact match rather than a fuzzy one', function (): void {
    turkishSearchProduct('Barkodlu Ürün', gtin: '8690000000017');
    turkishSearchProduct('Emaye Kupa');

    // The seller picker is the surface that searches by GTIN; the buyer listing
    // does not expose one.
    $browse = app(CatalogBrowseContract::class);

    expect($browse->searchPublishedProducts('8690000000017')['total'])->toBe(1)
        ->and($browse->searchPublishedProducts('8690000000018')['total'])->toBe(0);
});

it('fixes the seller picker too, not only the storefront', function (): void {
    turkishSearchProduct('Güneş Kremi SPF 50');
    turkishSearchProduct('Emaye Kupa');

    $browse = app(CatalogBrowseContract::class);

    expect($browse->searchPublishedProducts('gunes')['total'])->toBe(1)
        ->and($browse->searchPublishedProducts('güneş')['total'])->toBe(1);
});

it('rebuilds the haystack after a brand is renamed', function (): void {
    /*
    | The one drift the model hook cannot see: renaming a brand changes the
    | haystack of every product that mentions it, and nothing tells the product
    | row. `catalog:refresh-search-text` is the repair, the same shape as
    | `catalog:refresh-sellability` (ADR-079).
    */
    $brand = Brand::factory()->create(['name' => 'Eskisi']);

    turkishSearchProduct('Termal Su Spreyi', brand: $brand);
    turkishSearchProduct('Emaye Kupa');

    Brand::query()->whereKey($brand->getKey())->update(['name' => 'Yenisi']);

    expect(titlesIn($this->getJson(searchUrl('yenisi'))))->toBe([]);

    $this->artisan('catalog:refresh-search-text')->assertSuccessful();

    expect(titlesIn($this->getJson(searchUrl('yenisi'))))->toBe(['Termal Su Spreyi']);
});
