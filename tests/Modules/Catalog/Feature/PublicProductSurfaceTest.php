<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Application\Actions\PauseOfferAction;
use App\Modules\Offer\Application\Actions\UpdateOfferStockAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The buyer's product surface (ADR-058, Storefront.md §1.1)
|--------------------------------------------------------------------------
|
| THE FIRST SURFACE THAT ANSWERS "WHAT CAN I BUY HERE". Until now a buyer could
| read one store or one product's sellers; nothing looked across the marketplace.
|
| Its defining property is COMPOSITION: Catalog returns what a product IS and no
| price at all (ADR-037), while the SELLABLE filter comes from Offer through a
| Core contract. So the assertions come in two kinds — the listing shows only what
| can actually be bought, and the payload contains nothing commercial.
|
| THE LISTING AND THE PAGE DIFFER DELIBERATELY. The listing is sellable-only,
| because a card that leads to "unavailable" is a broken promise. The page is
| published-only, because a buyer arrives from a bookmark or a search engine long
| after the last seller ran out, and 404ing would break every link the platform
| has ever emitted.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A published product with a live, in-stock offer behind it.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{product: Product, offer: \App\Modules\Offer\Domain\Models\Offer, org: Organization, variant: ProductVariant}
 */
function sellableProduct(
    string $title = 'Pamuklu Tişört',
    int $priceMinor = 12_000,
    int $stock = 10,
    ?Category $category = null,
    ?Brand $brand = null,
): array {
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

    $offer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: $priceMinor,
        stockQuantity: $stock,
    ));

    return ['product' => $product, 'offer' => $offer, 'org' => $organization, 'variant' => $variant];
}

/*
|--------------------------------------------------------------------------
| The browse
|--------------------------------------------------------------------------
*/

it('lists a sellable published product, anonymously', function (): void {
    $fixture = sellableProduct('Pamuklu Tişört');

    // ANONYMOUS: this is browsing traffic, and requiring a login to look at a
    // shop would be the end of the shop.
    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $fixture['product']->uuid)
        ->assertJsonPath('data.0.title', 'Pamuklu Tişört');
});

it('hides a published product NOBODY sells', function (): void {
    // In the catalogue, correct, complete — and no merchant has offered it. A
    // card for it would lead straight to "unavailable".
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $orphan = Product::factory()->for($category, 'category')->published()->create();
    ProductVariant::factory()->for($orphan)->create();

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('hides a product whose only seller PAUSED or ran OUT', function (): void {
    $paused = sellableProduct('Duraklatılan');
    $soldOut = sellableProduct('Tükenen');
    sellableProduct('Satılabilir');

    app(PauseOfferAction::class)->run($paused['offer'], 'Stok bekleniyor');
    app(UpdateOfferStockAction::class)->run($soldOut['offer'], new UpdateOfferStockDTO(stockQuantity: 0));

    /*
     * THE SAME ELIGIBILITY AS THE BUY BOX, which is the point of asking Offer
     * rather than inventing a rule here: "appears in the listing" and "has a buy
     * box" cannot drift apart.
     */
    $response = $this->getJson('/api/v1/products')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.title'))->toBe('Satılabilir');
});

it('hides an unpublished product even when an offer somehow exists', function (): void {
    $fixture = sellableProduct('Yayında Olmayan');
    $fixture['product']->forceFill(['status' => \App\Modules\Catalog\Domain\Enums\ProductStatus::Archived])->save();

    // Two independent walls, and the moderation one holds on its own: a
    // published-state mistake must not become a public listing.
    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('carries NO price on a catalogue card', function (): void {
    sellableProduct();

    $response = $this->getJson('/api/v1/products')->assertOk();

    /*
     * ADR-037 IN AN ASSERTION. A price on this payload would mean a price in the
     * Catalog, which is the one thing that would stop one entry being sold by many
     * sellers. The storefront overlays it from `POST /offers/prices`.
     */
    $card = $response->json('data.0');

    foreach (['price', 'price_minor', 'stock', 'in_stock', 'currency'] as $forbidden) {
        expect($card)->not->toHaveKey($forbidden);
    }
});

it('never returns an internal id', function (): void {
    sellableProduct();

    $card = $this->getJson('/api/v1/products')->assertOk()->json('data.0');

    // `id` IS the uuid (non-negotiable #7). A sequential id on a public payload
    // tells anyone how big the catalogue is and lets them walk it.
    expect($card['id'])->toBeString()->toHaveLength(36)
        ->and($card['category']['id'])->toBeString()->toHaveLength(36);
});

it('filters by category, including everything filed beneath it', function (): void {
    $root = Category::factory()->create();
    $department = Category::factory()->childOf($root)->create();
    $leaf = Category::factory()->childOf($department)->create();

    sellableProduct('Derinde', category: $leaf);
    sellableProduct('Başka Yerde');

    // A shopper picking "Giyim" expects a t-shirt filed three levels down — they
    // think in departments, not in leaf nodes.
    $this->getJson('/api/v1/products?category='.$department->uuid)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Derinde');
});

it('returns nothing for a category that does not exist, never everything', function (): void {
    sellableProduct();

    // A filter that quietly stops filtering is worse than an empty result.
    $this->getJson('/api/v1/products?category=yok-boyle-bir-kategori')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters by brand', function (): void {
    $brand = Brand::factory()->create(['name' => 'Beko']);

    sellableProduct('Markalı', brand: $brand);
    sellableProduct('Markasız');

    $this->getJson('/api/v1/products?brand='.$brand->uuid)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.brand.name', 'Beko');
});

it('searches the title', function (): void {
    sellableProduct('Pamuklu Tişört');
    sellableProduct('Emaye Kupa');

    $this->getJson('/api/v1/products?q=Kupa')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Emaye Kupa');
});

it('sorts by the buy-box price, in both directions', function (): void {
    sellableProduct('Ucuz', priceMinor: 5_000);
    sellableProduct('Orta', priceMinor: 12_000);
    sellableProduct('Pahalı', priceMinor: 30_000);

    /*
     * ORDERED BY A NUMBER CATALOG DOES NOT HOLD. The price lives in Offer, so the
     * uuid list is sorted before the content is fetched and the content read is
     * re-ordered to match — a `whereIn` returns rows in the database's order, not
     * in the price order just computed.
     */
    $ascending = $this->getJson('/api/v1/products?sort=price_asc')->assertOk();

    expect(array_column($ascending->json('data'), 'title'))->toBe(['Ucuz', 'Orta', 'Pahalı']);

    $descending = $this->getJson('/api/v1/products?sort=price_desc')->assertOk();

    expect(array_column($descending->json('data'), 'title'))->toBe(['Pahalı', 'Orta', 'Ucuz']);
});

it('sorts by the CHEAPEST seller when a product has several', function (): void {
    $dear = sellableProduct('Çok Satıcılı', priceMinor: 30_000);
    sellableProduct('Tek Satıcılı', priceMinor: 10_000);

    // A second merchant undercuts the first on the same product.
    $rival = Organization::factory()->create();
    $rivalStore = Store::factory()->create([
        'organization_id' => $rival->getKey(),
        'status' => StoreStatus::Active,
    ]);

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $dear['variant']->uuid,
        sellingOrgId: $rival->getKey(),
        sellingOrgUuid: $rival->uuid,
        storeUuid: $rivalStore->uuid,
        priceMinor: 4_000,
        stockQuantity: 5,
    ));

    // The listing must sort on what the buyer would actually pay — the buy box —
    // not on whichever offer happens to be first.
    $response = $this->getJson('/api/v1/products?sort=price_asc')->assertOk();

    expect(array_column($response->json('data'), 'title'))->toBe(['Çok Satıcılı', 'Tek Satıcılı']);
});

it('falls back to the default sort rather than to no ordering', function (): void {
    sellableProduct();

    // An unrecognised value silently becoming "no ordering" would make the
    // listing's order depend on the database's mood — a bug that never
    // reproduces.
    $this->getJson('/api/v1/products?sort=; DROP TABLE products')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('paginates with an honest total', function (): void {
    foreach (range(1, 5) as $i) {
        sellableProduct("Ürün {$i}");
    }

    $response = $this->getJson('/api/v1/products?per_page=2')->assertOk();

    /*
     * The sellable filter is applied BEFORE pagination, deliberately: filtering a
     * page after fetching it gives pages of variable size and a wrong total —
     * "5 results" that yields 3 rows is a listing a client cannot paginate.
     */
    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.total'))->toBe(5)
        ->and($response->json('meta.last_page'))->toBe(3);
});

/*
|--------------------------------------------------------------------------
| The product page
|--------------------------------------------------------------------------
*/

it('returns the product page content, without a price', function (): void {
    $fixture = sellableProduct('Pamuklu Tişört');

    $response = $this->getJson('/api/v1/products/'.$fixture['product']->uuid)->assertOk();

    $response->assertJsonPath('data.id', $fixture['product']->uuid)
        ->assertJsonPath('data.title', 'Pamuklu Tişört')
        ->assertJsonCount(1, 'data.variants');

    // Variants are here, prices are not — that is what makes the page work: the
    // buyer picks a variant and the buy box says what it costs from whom.
    foreach (['price', 'price_minor', 'stock', 'offers'] as $forbidden) {
        expect($response->json('data'))->not->toHaveKey($forbidden);
    }
});

it('carries the category breadcrumb, root first', function (): void {
    /*
    | BOTH LOCALES SET TO THE SAME STRING: `localized('name')` resolves against
    | the ACTIVE locale and an HTTP request runs through `SetLocale`, so a fixture
    | with different names per locale would assert against whichever the middleware
    | picked — and fail for a reason unrelated to the breadcrumb.
    */
    $root = Category::factory()->create(['name_tr' => 'Giyim', 'name_en' => 'Giyim']);
    $leaf = Category::factory()->childOf($root)->create(['name_tr' => 'Tişört', 'name_en' => 'Tişört']);

    $fixture = sellableProduct(category: $leaf);

    $path = $this->getJson('/api/v1/products/'.$fixture['product']->uuid)
        ->assertOk()
        ->json('data.category.path');

    // A shopper reads a breadcrumb left to right; building it client-side would
    // mean exposing the tree.
    expect(array_column($path, 'name'))->toBe(['Giyim', 'Tişört']);
});

it('404s an unpublished product with no hint that it exists', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $draft = Product::factory()->for($category, 'category')->create();

    // ONE 404 for "no such product" and "not published": a draft's existence must
    // not be discoverable by watching which uuids answer differently.
    $this->getJson('/api/v1/products/'.$draft->uuid)->assertNotFound();
    $this->getJson('/api/v1/products/yok-boyle-bir-urun')->assertNotFound();
});

it('still renders the page of a product nobody currently sells', function (): void {
    $fixture = sellableProduct();
    app(UpdateOfferStockAction::class)->run($fixture['offer'], new UpdateOfferStockDTO(stockQuantity: 0));

    /*
     * THE DIFFERENCE FROM THE LISTING, and it is deliberate. A buyer arrives here
     * from a bookmark, a shared link or a search engine long after the last seller
     * ran out. The page is real; the buy box says nothing is available. 404ing it
     * would break every link the platform has ever emitted the moment stock ran
     * out.
     */
    $this->getJson('/api/v1/products/'.$fixture['product']->uuid)->assertOk();

    $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
});

it('does not leak seller-facing fields onto the page', function (): void {
    $fixture = sellableProduct();

    $payload = $this->getJson('/api/v1/products/'.$fixture['product']->uuid)->assertOk()->json('data');

    // Moderation state, provenance and the tax bracket are seller/staff concerns;
    // a public payload that carried them would tell a competitor who proposed
    // what, and tell everyone how the platform is wired.
    //
    // `gtin` is NOT on this list any more (owner-approved, 2026-08-01): the
    // barcode is printed on the physical product, so the page shows it. The
    // listing still does not — asserted in StorefrontPublicSurfaceTest, where the
    // two surfaces can be compared side by side.
    foreach (['status', 'proposed_by_org_uuid', 'moderation_reason', 'tax_rate_id'] as $forbidden) {
        expect($payload)->not->toHaveKey($forbidden);
    }
});

it('serves three sizes, each at the size its surface renders', function (): void {
    // The media disk is S3 in this environment; faked here rather than in
    // `beforeEach` so the rest of the file keeps running against the real config.
    Storage::fake(config('marketplace.media.public_disk'));

    ['product' => $product] = sellableProduct();

    app(App\Modules\Catalog\Application\Actions\AttachProductMediaAction::class)
        ->run($product, [
            UploadedFile::fake()->image('bir.jpg', 1600, 1600),
            UploadedFile::fake()->image('iki.jpg', 1600, 1600),
        ]);

    // Pretend the media queue has caught up, so the payload is choosing between
    // real conversions rather than falling back.
    foreach ($product->fresh()->getMedia('images') as $media) {
        $media->generated_conversions = ['thumb' => true, 'preview' => true, 'large' => true];
        $media->save();
    }

    $data = $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data');
    $gallery = $product->fresh()->getMedia('images');

    /*
     * **ONE FILE PER JOB.** The page used to serve a single URL to all three
     * surfaces, so either the thumbnail strip downloaded 1200px files to draw
     * them at 160, or the lightbox blew up a 480px one. Nothing caught it because
     * nothing asserted what the payload contained.
     */
    $bust = fn ($m): string => '?v='.$m->updated_at?->getTimestamp();

    expect($data['images'])->toBe($gallery->map(fn ($m): string => $m->getUrl('preview').$bust($m))->all())
        ->and($data['images_thumb'])->toBe($gallery->map(fn ($m): string => $m->getUrl('thumb').$bust($m))->all())
        ->and($data['images_large'])->toBe($gallery->map(fn ($m): string => $m->getUrl('large').$bust($m))->all());

    /*
     * **THE CACHE-BUSTER ITSELF**, which arrived without a test of its own. Spatie
     * names a conversion by convention and never changes it, so `regenerate`
     * overwrites the bytes at the SAME path and a CDN holding a 30-day copy keeps
     * serving the old image — the origin fixed, the visitor not. The token is the
     * media row's `updated_at`, so the URL changes exactly when the bytes do and
     * stays stable (fully cacheable) otherwise.
     */
    foreach (['images', 'images_thumb', 'images_large'] as $field) {
        foreach ($data[$field] as $i => $url) {
            expect($url)->toContain('?v='.$gallery[$i]->updated_at?->getTimestamp());
        }
    }

    /*
     * **AND THE INDICES LINE UP**, which is the entire contract a client relies on:
     * `images_large[1]` must be the big version of `images[1]`. Two images, so an
     * ordering mistake has somewhere to show.
     */
    expect($data['images'])->toHaveCount(2)
        ->and($data['images_thumb'])->toHaveCount(2)
        ->and($data['images_large'])->toHaveCount(2);

    // THE PATH, WITHOUT THE `?v=` TOKEN — the filename is what carries the
    // ordering, and the query string would otherwise land inside the stem.
    $stemOf = static fn (string $url): string => str_replace(
        ['-preview.webp', '-thumb.webp', '-large.webp'],
        '',
        basename((string) parse_url($url, PHP_URL_PATH)),
    );

    foreach ([0, 1] as $i) {
        $stem = $stemOf($data['images'][$i]);

        expect($stemOf($data['images_thumb'][$i]))->toBe($stem)
            ->and($stemOf($data['images_large'][$i]))->toBe($stem);
    }
});

it('still hands back the original for every size when the conversions are not generated yet', function (): void {
    Storage::fake(config('marketplace.media.public_disk'));

    // A COLD MEDIA QUEUE, which is the state this test is about. Without the fake
    // the conversions run inline and there is nothing to fall back from.
    Queue::fake();

    ['product' => $product] = sellableProduct();

    app(App\Modules\Catalog\Application\Actions\AttachProductMediaAction::class)
        ->run($product, [UploadedFile::fake()->image('urun.jpg', 1600, 1600)]);

    $media = $product->fresh()->getFirstMedia('images');

    /*
     * THE PROPERTY THAT MAKES THREE FIELDS SAFE WITH A COLD QUEUE: Spatie builds a
     * conversion URL by convention rather than by looking at the disk, so asking
     * for one that has not been generated returns a path that 404s. The shared
     * helper checks first and serves the ORIGINAL — so a stopped worker costs
     * bandwidth, not broken images, on all three fields alike.
     */
    expect($media->hasGeneratedConversion('large'))->toBeFalse();

    $data = $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data');

    $version = '?v='.$media->updated_at?->getTimestamp();

    expect($data['images'])->toBe([$media->getUrl().$version])
        ->and($data['images_thumb'])->toBe([$media->getUrl().$version])
        ->and($data['images_large'])->toBe([$media->getUrl().$version]);
});
