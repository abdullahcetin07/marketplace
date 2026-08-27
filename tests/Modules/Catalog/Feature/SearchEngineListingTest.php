<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Contracts\ProductSearchContract;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| The engine ranks, the database still decides who may see it (ADR-090)
|--------------------------------------------------------------------------
|
| Meilisearch is not running in this suite and must not need to be: what these
| tests pin is the SEAM. The engine answers with an ordered list of uuids or
| with `null`, and everything interesting follows from which of the two it is.
|
| `null` is the state during a rollout and during an outage, and it must mean
| "use the Tier-1 fold", never "no results" — an empty page for every search is
| how a working catalogue looks broken.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A stub engine. Returns exactly what a test tells it to, including `null`.
 *
 * @param array<int, string>|null $ranked
 * @param array{products: array<int, array<string, string>>, brands: array<int, string>, categories: array<int, string>}|null $suggestions
 */
function fakeEngine(?array $ranked, ?array $suggestions = null): void
{
    app()->instance(ProductSearchContract::class, new class($ranked, $suggestions) implements ProductSearchContract
    {
        /**
         * @param array<int, string>|null $ranked
         * @param array{products: array<int, array<string, string>>, brands: array<int, string>, categories: array<int, string>}|null $suggestions
         */
        public function __construct(private readonly ?array $ranked, private readonly ?array $suggestions) {}

        public function rankedUuids(string $query, int $limit = 500): ?array
        {
            return $this->ranked;
        }

        public function suggest(string $query, int $products = 6, int $brands = 4, int $categories = 4): ?array
        {
            return $this->suggestions;
        }

        public function isAvailable(): bool
        {
            return $this->ranked !== null;
        }

        public function enabled(): bool
        {
            return $this->ranked !== null;
        }
    });
}

/** A published, sellable product. */
function searchableProduct(string $title, int $priceMinor = 12_000, ?Brand $brand = null): Product
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $product = Product::factory()
        ->for(Category::factory()->childOf(Category::factory()->create())->create(), 'category')
        ->published()
        ->create(['title_tr' => $title, 'title_en' => $title, 'brand_id' => $brand?->getKey()]);

    $variant = ProductVariant::factory()->for($product)->create();

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: $priceMinor,
        stockQuantity: 5,
    ));

    return $product->refresh();
}

it('returns the engine order, not the newest-first order', function (): void {
    /*
    | The point of an engine. Created oldest-to-newest, ranked the other way
    | round: if the listing were still ordering by `published_at` the assertion
    | below would come back reversed.
    */
    $first = searchableProduct('Serum A');
    $second = searchableProduct('Serum B');
    $third = searchableProduct('Serum C');

    fakeEngine([$second->uuid, $third->uuid, $first->uuid]);

    $titles = array_column($this->getJson('/api/v1/products?q=serum')->assertOk()->json('data'), 'title');

    expect($titles)->toBe(['Serum B', 'Serum C', 'Serum A']);
});

it('keeps the engine set but the shopper order when they sort by price', function (): void {
    $cheap = searchableProduct('Serum Ucuz', priceMinor: 5_000);
    $dear = searchableProduct('Serum Pahalı', priceMinor: 30_000);
    searchableProduct('Serum Görünmez');

    // The engine ranks the dear one first; the shopper asked for cheapest.
    fakeEngine([$dear->uuid, $cheap->uuid]);

    $titles = array_column(
        $this->getJson('/api/v1/products?q=serum&sort=price_asc')->assertOk()->json('data'),
        'title',
    );

    expect($titles)->toBe(['Serum Ucuz', 'Serum Pahalı']);
});

it('paginates the ranked set without losing the order', function (): void {
    $products = collect(range(1, 5))->map(fn (int $i): Product => searchableProduct("Krem {$i}"));

    // Deliberately not the creation order.
    $ranked = [
        $products[4]->uuid, $products[0]->uuid, $products[3]->uuid,
        $products[1]->uuid, $products[2]->uuid,
    ];

    fakeEngine($ranked);

    $page1 = array_column($this->getJson('/api/v1/products?q=krem&per_page=2&page=1')->json('data'), 'title');
    $page2 = array_column($this->getJson('/api/v1/products?q=krem&per_page=2&page=2')->json('data'), 'title');
    $meta = $this->getJson('/api/v1/products?q=krem&per_page=2&page=2')->json('meta');

    expect($page1)->toBe(['Krem 5', 'Krem 1'])
        ->and($page2)->toBe(['Krem 4', 'Krem 2'])
        ->and($meta['total'])->toBe(5);
});

it('never lets an engine hit past the sellable wall', function (): void {
    /*
    | The index knows what a product IS; it does not know whether anybody
    | stocks it, and the engine's answer is a match set, not a permission.
    */
    $sellable = searchableProduct('Serum Satılan');
    $unsellable = Product::factory()
        ->for(Category::factory()->childOf(Category::factory()->create())->create(), 'category')
        ->published()
        ->create(['title_tr' => 'Serum Satılmayan', 'title_en' => 'Serum Satılmayan']);

    fakeEngine([$unsellable->uuid, $sellable->uuid]);

    $titles = array_column($this->getJson('/api/v1/products?q=serum')->assertOk()->json('data'), 'title');

    expect($titles)->toBe(['Serum Satılan']);
});

it('falls back to the fold when there is no engine', function (): void {
    /*
    | The resilience posture in one assertion: no engine, and `gunes` still
    | finds `Güneş` through the Tier-1 folded column (ADR-089). Worse search,
    | not no search.
    */
    searchableProduct('Güneş Kremi');
    searchableProduct('Emaye Kupa');

    fakeEngine(null);

    $titles = array_column($this->getJson('/api/v1/products?q=gunes')->assertOk()->json('data'), 'title');

    expect($titles)->toBe(['Güneş Kremi']);
});

it('reports an empty engine answer as no results, not as a fallback', function (): void {
    /*
    | The other half of the null/[] distinction. The engine looked and found
    | nothing — falling back to a substring search here would resurrect results
    | the engine deliberately excluded.
    */
    searchableProduct('Güneş Kremi');
    searchableProduct('Emaye Kupa');

    fakeEngine([]);

    expect($this->getJson('/api/v1/products?q=gunes')->assertOk()->json('data'))->toBe([]);
});

it('suggests products, brands and categories under their caps', function (): void {
    $suggestions = [
        'products' => [['uuid' => 'u1', 'title' => 'Serum', 'slug' => 'serum']],
        'brands' => ['Uriage'],
        'categories' => ['Serumlar'],
    ];

    fakeEngine(['irrelevant'], $suggestions);

    $this->getJson('/api/v1/search/suggest?q=ser')
        ->assertOk()
        ->assertJsonPath('data.products.0.title', 'Serum')
        ->assertJsonPath('data.brands.0', 'Uriage')
        ->assertJsonPath('data.categories.0', 'Serumlar');
});

it('suggests nothing for an empty box and falls back without an engine', function (): void {
    $brand = Brand::factory()->create(['name' => 'Uriage']);
    searchableProduct('Güneş Kremi', brand: $brand);
    searchableProduct('Emaye Kupa');

    fakeEngine(null);

    $this->getJson('/api/v1/search/suggest?q=')
        ->assertOk()
        ->assertJsonPath('data.products', [])
        ->assertJsonPath('data.brands', []);

    $fallback = $this->getJson('/api/v1/search/suggest?q=gunes')->assertOk()->json('data');

    expect($fallback['products'])->toHaveCount(1)
        ->and($fallback['products'][0]['title'])->toBe('Güneş Kremi')
        ->and($fallback['brands'])->toBe(['Uriage']);
});

it('says on the health endpoint whether search is engine-backed', function (): void {
    fakeEngine(null);

    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.search', 'disabled');

    fakeEngine([]);

    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.search', 'up');
});

it('keeps price and stock out of the search document', function (): void {
    /*
    | `CatalogBoundaryTest` guards the module; this guards the INDEX, which is
    | the newest way for a price to escape Offer (ADR-037/090).
    */
    $product = searchableProduct('Serum');

    $document = $product->fresh()->toSearchableArray();

    expect(array_keys($document))->not->toContain('price')
        ->and(array_keys($document))->not->toContain('price_minor')
        ->and(array_keys($document))->not->toContain('stock')
        ->and($document['is_sellable'])->toBeTrue();
});
