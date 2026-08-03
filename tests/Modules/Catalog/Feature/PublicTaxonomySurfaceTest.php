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

/*
|--------------------------------------------------------------------------
| The flat URL surfaces: resolve, categories, brands (ADR-059)
|--------------------------------------------------------------------------
|
| The storefront addresses everything at the root, so it has ONE catch-all route
| and no way to tell a product slug from a brand slug. `/resolve` is what makes
| that work, and `/categories` + `/brands` are the pages the scheme creates.
|
| What is pinned here:
|
|   RESOLVE      the right type, and 404 for anything a buyer may not see
|   CANONICAL    a retired alias reports where it moved, for a 301
|   COUNTS       are SELLABLE, so a menu and the listing it opens agree
|   SLUG OR UUID both address every surface — resolved BY SHAPE
|   NEVER 500    a made-up slug is a 404 or an empty list, never a uuid cast
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A sellable product under Kozmetik → Cilt Bakımı, from brand Bioderma.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{product: Product, category: Category, parent: Category, brand: Brand}
 */
function taxonomyFixture(): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $parent = Category::factory()->create(['name_tr' => 'Kozmetik', 'name_en' => 'Kozmetik', 'slug' => 'kozmetik']);
    $category = Category::factory()->childOf($parent)->create([
        'name_tr' => 'Cilt Bakımı',
        'name_en' => 'Cilt Bakımı',
        'slug' => 'cilt-bakimi',
    ]);

    $brand = Brand::factory()->create(['name' => 'Bioderma', 'slug' => 'bioderma']);

    $product = Product::factory()->for($category, 'category')->for($brand)->published()->create([
        'title_tr' => 'Sensibio Krem',
        'title_en' => 'Sensibio Krem',
        'slug' => 'sensibio-krem',
    ]);

    $variant = ProductVariant::factory()->for($product)->create();

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: 24_900,
        stockQuantity: 5,
    ));

    return ['product' => $product, 'category' => $category, 'parent' => $parent, 'brand' => $brand];
}

/*
|--------------------------------------------------------------------------
| The resolver
|--------------------------------------------------------------------------
*/

it('tells the storefront which kind of page a root slug is', function (): void {
    taxonomyFixture();

    // The one endpoint the flat scheme requires: `/bioderma`, `/cilt-bakimi` and
    // `/sensibio-krem` are indistinguishable to a client, and trying all three
    // page endpoints in turn would be three requests and two 404s per first paint.
    foreach ([
        'sensibio-krem' => 'product',
        'cilt-bakimi' => 'category',
        'bioderma' => 'brand',
    ] as $slug => $type) {
        $this->getJson('/api/v1/resolve/'.$slug)
            ->assertOk()
            ->assertJsonPath('data.type', $type)
            ->assertJsonPath('data.slug', $slug)
            ->assertJsonPath('data.canonical_slug', $slug);
    }
});

it('reports the canonical address when a visitor lands on a retired alias', function (): void {
    $fixture = taxonomyFixture();

    // A deliberate slug change. The old address must keep answering, or every
    // inbound link ever shared dies at once.
    $fixture['product']->update(['slug' => 'sensibio-ar-cc-krem']);

    $this->getJson('/api/v1/resolve/sensibio-krem')
        ->assertOk()
        ->assertJsonPath('data.type', 'product')
        // `slug` is what they asked for, `canonical_slug` is where it lives —
        // different means "301 there", which is what stops the platform serving
        // two URLs for one page forever.
        ->assertJsonPath('data.slug', 'sensibio-krem')
        ->assertJsonPath('data.canonical_slug', 'sensibio-ar-cc-krem');
});

it('never resolves a slug to something a buyer may not see', function (): void {
    $fixture = taxonomyFixture();

    /*
     * A registry row exists from the moment an entity is SAVED — the trait puts
     * it there — so a draft product has an address before a moderator has seen
     * it. Resolving without a publication check would let anyone enumerate
     * unreleased products by guessing names.
     */
    $draft = Product::factory()->for($fixture['category'], 'category')->create(['slug' => 'gizli-urun']);
    $hidden = Category::factory()->create(['slug' => 'gizli-kategori', 'is_active' => false]);
    $retired = Brand::factory()->create(['slug' => 'gizli-marka', 'is_active' => false]);

    foreach (['gizli-urun', 'gizli-kategori', 'gizli-marka', 'hic-olmayan-slug'] as $slug) {
        $this->getJson('/api/v1/resolve/'.$slug)->assertNotFound();
    }

    // Asserted so the fixtures above cannot silently stop existing.
    expect($draft->exists && $hidden->exists && $retired->exists)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

it('serves the category tree with sellable counts rolled up', function (): void {
    taxonomyFixture();

    /** @var array<int, array<string, mixed>> $tree */
    $tree = $this->getJson('/api/v1/categories')->assertOk()->json('data');

    $kozmetik = collect($tree)->firstWhere('slug', 'kozmetik');

    /*
     * THE ROLL-UP. The product is filed at "Cilt Bakımı", two levels down, and a
     * shopper picking "Kozmetik" expects to find it — so a parent's count
     * includes everything beneath it, exactly as the listing's category filter
     * includes descendants.
     */
    expect($kozmetik['product_count'])->toBe(1)
        ->and($kozmetik['parent_id'])->toBeNull()
        ->and($kozmetik['children'][0]['slug'])->toBe('cilt-bakimi')
        ->and($kozmetik['children'][0]['product_count'])->toBe(1);
});

it('counts only what somebody is actually selling', function (): void {
    $fixture = taxonomyFixture();

    // Published, complete, and nobody stocks it. A menu that says "2" and opens
    // on a listing of 1 is a menu a shopper stops trusting.
    $unsold = Product::factory()->for($fixture['category'], 'category')->published()->create();
    ProductVariant::factory()->for($unsold)->create();

    $node = $this->getJson('/api/v1/categories/cilt-bakimi')->assertOk()->json('data');

    expect($node['product_count'])->toBe(1);
});

it('gives a category page its breadcrumb and its children', function (): void {
    $fixture = taxonomyFixture();

    $node = $this->getJson('/api/v1/categories/cilt-bakimi')->assertOk()->json('data');

    // Root first and INCLUDING the category itself — a breadcrumb that stops at
    // the parent makes every client append the last crumb by hand.
    expect(array_column($node['path'], 'slug'))->toBe(['kozmetik', 'cilt-bakimi'])
        ->and($node['id'])->toBe($fixture['category']->uuid)
        ->and($node['children'])->toBe([]);
});

it('addresses a category by slug or by uuid, and 404s anything else', function (): void {
    $fixture = taxonomyFixture();

    $bySlug = $this->getJson('/api/v1/categories/cilt-bakimi')->assertOk()->json('data');
    $byUuid = $this->getJson('/api/v1/categories/'.$fixture['category']->uuid)->assertOk()->json('data');

    expect($bySlug)->toBe($byUuid);

    // THE 500 THIS SPRINT EXISTED TO END. A made-up value is a miss, not a cast.
    $this->getJson('/api/v1/categories/hic-olmayan')->assertNotFound();
    $this->getJson('/api/v1/categories/'.(string) Str::uuid())->assertNotFound();

    // And a slug that belongs to a BRAND is not a category, however valid it is.
    $this->getJson('/api/v1/categories/bioderma')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Brands
|--------------------------------------------------------------------------
*/

it('lists only brands somebody is selling, but still renders a quiet brand’s page', function (): void {
    taxonomyFixture();

    $empty = Brand::factory()->create(['name' => 'Kimse Satmiyor', 'slug' => 'kimse-satmiyor']);

    $list = $this->getJson('/api/v1/brands')->assertOk()->json('data');

    /*
     * OMITTED FROM THE LIST, because a brand filter offering 400 names that
     * return nothing for 380 of them is a filter nobody uses twice.
     */
    expect(array_column($list, 'slug'))->toBe(['bioderma'])
        ->and($list[0]['product_count'])->toBe(1);

    /*
     * BUT ITS PAGE STILL RENDERS — the same distinction the product page keeps.
     * A buyer arrives from a bookmark long after the last seller ran out, and
     * 404ing would break every link the moment stock did.
     */
    $this->getJson('/api/v1/brands/'.$empty->slug)
        ->assertOk()
        ->assertJsonPath('data.product_count', 0);
});

it('addresses a brand by slug or by uuid, and 404s anything else', function (): void {
    $fixture = taxonomyFixture();

    $bySlug = $this->getJson('/api/v1/brands/bioderma')->assertOk()->json('data');
    $byUuid = $this->getJson('/api/v1/brands/'.$fixture['brand']->uuid)->assertOk()->json('data');

    expect($bySlug)->toBe($byUuid);

    $this->getJson('/api/v1/brands/hic-olmayan')->assertNotFound();
    // A category slug is not a brand.
    $this->getJson('/api/v1/brands/cilt-bakimi')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The product surfaces, now slug-addressed
|--------------------------------------------------------------------------
*/

it('opens a product page by slug as well as by uuid', function (): void {
    $fixture = taxonomyFixture();

    $bySlug = $this->getJson('/api/v1/products/sensibio-krem')->assertOk()->json('data');
    $byUuid = $this->getJson('/api/v1/products/'.$fixture['product']->uuid)->assertOk()->json('data');

    expect($bySlug)->toBe($byUuid)
        // Every breadcrumb node is a LINK, so it carries its address.
        ->and(array_column($bySlug['category']['path'], 'slug'))->toBe(['kozmetik', 'cilt-bakimi'])
        ->and($bySlug['brand']['slug'])->toBe('bioderma');

    // A brand slug is not a product — otherwise `/products/bioderma` would render
    // something unrelated instead of 404ing.
    $this->getJson('/api/v1/products/bioderma')->assertNotFound();
});

it('filters the listing by a category or brand SLUG, and empties on a miss', function (): void {
    $fixture = taxonomyFixture();

    // THE LIVE 500. `?category=Dermokozmetik` reached `where('uuid', <name>)` and
    // was SQLSTATE[22P02] on PostgreSQL — a crash on the most ordinary call the
    // listing has.
    expect($this->getJson('/api/v1/products?category=cilt-bakimi')->assertOk()->json('data'))->toHaveCount(1)
        // The parent, because a category filter includes descendants.
        ->and($this->getJson('/api/v1/products?category=kozmetik')->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/products?brand=bioderma')->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/products?category='.$fixture['category']->uuid)->assertOk()->json('data'))
        ->toHaveCount(1);

    /*
     * A FILTER THAT CANNOT BE RESOLVED MATCHES NOTHING — it does not silently
     * stop filtering. Returning the whole catalogue for `?category=made-up` would
     * be a wrong answer dressed as a right one.
     */
    expect($this->getJson('/api/v1/products?category=hic-olmayan')->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/products?brand=hic-olmayan')->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/products?category=Dermokozmetik')->assertOk()->json('data'))->toBe([]);
});
