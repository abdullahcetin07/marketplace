<?php

declare(strict_types=1);

use App\Core\Domain\Storefront\StorefrontContext;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Storefront\OfferStorefrontContributor;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The storefront product-listing contributor (ADR-046, fulfilling ADR-041)
|--------------------------------------------------------------------------
|
| The piece that makes a storefront a shop. Catalog registered no contributor
| because "a store's products" means its offers, which did not exist; this is
| that section, arriving through the Core seam so Store still depends on nothing.
|
| Two properties matter beyond "it renders":
|
|  1. It reaches the storefront THROUGH the registry, not by Store naming Offer.
|     The end-to-end test hits the real public store route and looks for the
|     section under `extensions` — that is the only proof the composition works.
|  2. It must not throw. A broken listing section taking down a store's whole
|     public page is precisely what ADR-036's resilience rule exists to prevent.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A live store with a published product it sells.
 *
 * @return array{store: Store, product: Product, offer: Offer}
 */
function storeWithListing(): array
{
    $store = Store::factory()->create(['status' => StoreStatus::Active]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()
        ->create(['title_tr' => 'Pamuklu Tişört', 'title_en' => 'Cotton T-Shirt']);

    return [
        'store' => $store,
        'product' => $product,
        'offer' => Offer::factory()->priced(12_990)
            ->forVariant('v-1', $product->uuid)
            ->forStore($store->uuid)
            ->create(),
    ];
}

function contextFor(Store $store): StorefrontContext
{
    return new StorefrontContext(
        storeId: $store->getKey(),
        storeUuid: $store->uuid,
        slug: $store->slug,
        organizationId: $store->organization_id,
        languageCode: 'tr',
        currencyCode: 'TRY',
    );
}

it('contributes a store’s listings under a stable section key', function (): void {
    $fixture = storeWithListing();

    $contributor = app(OfferStorefrontContributor::class);

    // The key is a public promise — it becomes a key in `extensions`, so
    // changing it breaks deployed clients silently.
    expect($contributor->key())->toBe('products');

    $section = $contributor->contribute(contextFor($fixture['store']));

    expect($section['total'])->toBe(1)
        ->and($section['items'][0]['id'])->toBe($fixture['offer']->uuid)
        ->and($section['items'][0]['title'])->toBe($fixture['product']->localized('title'))
        // Money as a decimal string here too — the storefront is a public
        // surface like any other (005 §28).
        ->and($section['items'][0]['price'])->toBe('129.90')
        ->and($section['items'][0]['in_stock'])->toBeTrue();
});

it('surfaces the section on the real public storefront route', function (): void {
    $fixture = storeWithListing();

    // The end-to-end proof: Store assembles, the registry resolves Offer's
    // contributor, and the section lands under `extensions` — with Store
    // never naming Offer anywhere.
    $this->getJson('/api/v1/store/'.$fixture['store']->slug)
        ->assertOk()
        ->assertJsonPath('data.extensions.products.total', 1)
        ->assertJsonPath('data.extensions.products.items.0.id', $fixture['offer']->uuid);
});

it('contributes an empty section for a store with nothing listed', function (): void {
    $store = Store::factory()->create(['status' => StoreStatus::Active]);

    // A live store with no listings is an ordinary state — it was approved
    // before its first product went up — not an error and not a missing key.
    $section = app(OfferStorefrontContributor::class)->contribute(contextFor($store));

    expect($section)->toBe(['items' => [], 'total' => 0]);
});

it('never contributes another store’s listings', function (): void {
    $mine = storeWithListing();
    $theirs = storeWithListing();

    $section = app(OfferStorefrontContributor::class)->contribute(contextFor($mine['store']));

    expect(array_column($section['items'], 'id'))->toBe([$mine['offer']->uuid])
        ->and(array_column($section['items'], 'id'))->not->toContain($theirs['offer']->uuid);
});

it('omits a listing whose product the catalog no longer publishes', function (): void {
    $fixture = storeWithListing();
    $fixture['product']->forceFill(['status' => \App\Modules\Catalog\Domain\Enums\ProductStatus::Archived])->save();

    $section = app(OfferStorefrontContributor::class)->contribute(contextFor($fixture['store']));

    // The cascade normally pauses these first (§3.5); this is the belt under
    // that brace — a storefront must never render a title the catalog has
    // withdrawn.
    expect($section['items'])->toBe([]);
});

it('excludes paused and out-of-stock listings from the storefront', function (): void {
    $fixture = storeWithListing();

    Offer::factory()->paused()->forVariant('v-2', $fixture['product']->uuid)
        ->forStore($fixture['store']->uuid)->create();
    Offer::factory()->outOfStock()->forVariant('v-3', $fixture['product']->uuid)
        ->forStore($fixture['store']->uuid)->create();

    $section = app(OfferStorefrontContributor::class)->contribute(contextFor($fixture['store']));

    expect($section['total'])->toBe(1)
        ->and(array_column($section['items'], 'id'))->toBe([$fixture['offer']->uuid]);
});

it('gives each store card a picture and a canonical link', function (): void {
    $fixture = storeWithListing();

    Storage::fake(config('marketplace.media.public_disk'));

    app(App\Modules\Catalog\Application\Actions\AttachProductMediaAction::class)
        ->run($fixture['product'], [UploadedFile::fake()->image('urun.jpg', 1600, 1600)]);

    $items = app(OfferStorefrontContributor::class)
        ->contribute(contextFor($fixture['store']))['items'];

    /*
     * **BOTH ARE CATALOG'S FACTS, ARRIVING WITH THE TITLE.** Without them a store
     * card can only draw a placeholder and link through a uuid that 301s to the
     * slug — working, and two things a shopper notices.
     */
    expect($items[0]['image'])->toBeString()
        ->and($items[0]['image'])->toContain('preview')
        // The canonical slug (ADR-059) — what `/{slug}` resolves without a hop.
        ->and($items[0]['slug'])->toBe($fixture['product']->slug);
});

it('says null rather than inventing an image for a product without one', function (): void {
    $fixture = storeWithListing();

    $items = app(OfferStorefrontContributor::class)
        ->contribute(contextFor($fixture['store']))['items'];

    /*
     * NULL IS THE ANSWER THE STOREFRONT IS BUILT FOR: it draws its own placeholder.
     * An empty string or a made-up path would render as a broken image instead.
     */
    expect($items[0]['image'])->toBeNull()
        ->and($items[0]['slug'])->toBe($fixture['product']->slug);
});

it('carries the fields all the way to the store page', function (): void {
    $fixture = storeWithListing();

    $item = $this->getJson('/api/v1/magaza/'.$fixture['store']->slug)
        ->assertOk()
        ->json('data.extensions.products.items.0');

    // The composed read (ADR-036) is what the storefront actually calls.
    expect($item)->toHaveKeys(['image', 'slug'])
        ->and($item['slug'])->toBe($fixture['product']->slug);
});
