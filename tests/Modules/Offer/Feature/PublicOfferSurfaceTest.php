<?php

declare(strict_types=1);

use App\Core\Presentation\Support\MoneyString;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| The public product-offers surface (§5) — the first buyer-facing route
|--------------------------------------------------------------------------
|
| Unauthenticated, so everything it returns is a permanent public promise and
| everything it withholds is a decision. What is pinned:
|
|  1. Money crosses as a DECIMAL STRING paired with its currency (005 §28).
|     A JSON number would be parsed as a float and undo integer storage.
|  2. No internal id, no stock count. "3 left" tells a rival exactly what a
|     seller holds; the boolean is all a buyer needs.
|  3. An unpublished product 404s (existence must not leak); a published one
|     nobody sells returns `featured: null`, because a buyer can legitimately
|     land there from a bookmark.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A published product with a live store to sell it from.
 *
 * @return array{product: Product, store: string}
 */
function publiclyOfferedProduct(): array
{
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    // Both locales, because `localized()` resolves against the app locale and
    // the suite does not run in Turkish.
    $product = Product::factory()->for($category, 'category')->published()
        ->create(['title_tr' => 'Pamuklu Tişört', 'title_en' => 'Cotton T-Shirt']);

    return [
        'product' => $product,
        'store' => Store::factory()->create(['status' => StoreStatus::Active])->uuid,
    ];
}

function offersUrl(string $productUuid): string
{
    return '/api/v1/products/'.$productUuid.'/offers';
}

it('returns the product, the buy box winner and the other sellers in order', function (): void {
    $fixture = publiclyOfferedProduct();

    $cheap = Offer::factory()->priced(12_990)->forVariant('v-1', $fixture['product']->uuid)
        ->forStore($fixture['store'])->create();
    $mid = Offer::factory()->priced(15_990)->forVariant('v-2', $fixture['product']->uuid)
        ->forStore($fixture['store'])->create();

    $response = $this->getJson(offersUrl($fixture['product']->uuid))->assertOk();

    $response->assertJsonPath('data.product.title', $fixture['product']->localized('title'))
        ->assertJsonPath('data.featured.id', $cheap->uuid)
        ->assertJsonPath('data.other_sellers.0.id', $mid->uuid)
        ->assertJsonPath('data.offer_count', 2);
});

it('renders money as a decimal string with its currency, never a number', function (): void {
    $fixture = publiclyOfferedProduct();
    Offer::factory()->priced(12_990, 19_990)->forVariant('v-1', $fixture['product']->uuid)
        ->forStore($fixture['store'])->create();

    $response = $this->getJson(offersUrl($fixture['product']->uuid))->assertOk();

    $price = $response->json('data.featured.price');

    expect($price)->toBeString()->toBe('129.90');

    // The platform default currency's code, read rather than hard-coded: which
    // currency is default is seeded data, not a fact this test owns.
    $response->assertJsonPath('data.featured.list_price', '199.90')
        ->assertJsonPath(
            'data.featured.currency',
            app(CurrencyRepositoryContract::class)->default()->code,
        );
});

it('exposes no internal id and no stock count', function (): void {
    $fixture = publiclyOfferedProduct();
    Offer::factory()->priced(9_990)->forVariant('v-1', $fixture['product']->uuid)
        ->forStore($fixture['store'])->create(['stock_quantity' => 37]);

    $featured = $this->getJson(offersUrl($fixture['product']->uuid))->assertOk()
        ->json('data.featured');

    // Non-negotiable #7, and the merchandising decision this surface refuses to
    // make silently.
    expect($featured)->not->toHaveKey('id_internal')
        ->and($featured)->not->toHaveKey('price_minor')
        ->and($featured)->not->toHaveKey('stock_quantity')
        ->and($featured)->not->toHaveKey('selling_org_id')
        ->and($featured['in_stock'])->toBeTrue();
});

it('returns the product with no featured offer when nothing is sellable', function (): void {
    $fixture = publiclyOfferedProduct();
    Offer::factory()->outOfStock()->forVariant('v-1', $fixture['product']->uuid)
        ->forStore($fixture['store'])->create();

    // A real page a buyer may land on from search — "currently unavailable",
    // not a 404.
    $this->getJson(offersUrl($fixture['product']->uuid))
        ->assertOk()
        ->assertJsonPath('data.featured', null)
        ->assertJsonPath('data.offer_count', 0)
        ->assertJsonPath('data.product.title', $fixture['product']->localized('title'));
});

it('404s for an unpublished product, exactly as for one that does not exist', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $draft = Product::factory()->for($category, 'category')->create();

    // The same answer for both, so a draft's existence never leaks.
    $this->getJson(offersUrl($draft->uuid))->assertNotFound();
    $this->getJson(offersUrl('no-such-product'))->assertNotFound();
});

it('hides an offer whose store is not live from the public page', function (): void {
    $fixture = publiclyOfferedProduct();
    $dark = Store::factory()->create(['status' => StoreStatus::Suspended])->uuid;

    Offer::factory()->priced(1_000)->forVariant('v-1', $fixture['product']->uuid)
        ->forStore($dark)->create();
    $visible = Offer::factory()->priced(9_990)->forVariant('v-2', $fixture['product']->uuid)
        ->forStore($fixture['store'])->create();

    $this->getJson(offersUrl($fixture['product']->uuid))
        ->assertOk()
        ->assertJsonPath('data.featured.id', $visible->uuid)
        ->assertJsonPath('data.offer_count', 1);
});

it('names the shop a buyer is buying from, through the Store contract', function (): void {
    $fixture = publiclyOfferedProduct();

    $named = Store::factory()->create([
        'status' => StoreStatus::Active,
        'name' => 'Deniz Kozmetik',
    ]);

    Offer::factory()->priced(9_990)->forVariant('v-1', $fixture['product']->uuid)
        ->forStore($named->uuid)->create();

    $featured = $this->getJson(offersUrl($fixture['product']->uuid))->assertOk()
        ->json('data.featured');

    /*
     * THE ONE JOB OF A SELLER ROW. Before this the payload carried a bare
     * `store_id`, so a buy box could render "Satıcı: a1086566-10aa-…" and nothing
     * else — Offer holds store uuids and may not import Store (ADR-033), so the
     * name arrives through `StoreQueryContract` or not at all.
     *
     * The uuid did not go anywhere; it moved under `store.id` and gained a name to
     * stand next to.
     */
    expect($featured['store'])->toBe([
        'id' => $named->uuid,
        'name' => 'Deniz Kozmetik',
        // No seller-facing form writes a contact address yet, so this is null on
        // every store today. Asserted rather than skipped, so the day one does the
        // failure points at this line instead of at a frontend that shows nothing.
        'city' => null,
    ]);
});

it('shows the seller’s city once the store holds one', function (): void {
    $fixture = publiclyOfferedProduct();

    $store = Store::factory()->create(['status' => StoreStatus::Active, 'name' => 'Ege Ticaret']);
    // Written straight to the contact block: `store_contacts.address` is free-form
    // `jsonb` (Store §2.6) and this is the key the buy box reads.
    $store->contact()->create(['address' => ['city' => 'İzmir', 'line1' => 'Alsancak']]);

    Offer::factory()->priced(9_990)->forVariant('v-1', $fixture['product']->uuid)
        ->forStore($store->uuid)->create();

    $this->getJson(offersUrl($fixture['product']->uuid))->assertOk()
        ->assertJsonPath('data.featured.store.city', 'İzmir')
        // ONLY the city. The rest of the address is a shipping origin, not a
        // shopfront label, and a public payload gets the label.
        ->assertJsonMissing(['line1' => 'Alsancak']);
});

it('survives a malformed store address rather than 500ing a product page', function (): void {
    $fixture = publiclyOfferedProduct();

    $store = Store::factory()->create(['status' => StoreStatus::Active]);
    // The column has no enforced shape, so this is reachable data, not a
    // hypothetical — and one seller's bad profile must not take a public page down.
    $store->contact()->create(['address' => ['city' => ['nested', 'nonsense']]]);

    Offer::factory()->priced(9_990)->forVariant('v-1', $fixture['product']->uuid)
        ->forStore($store->uuid)->create();

    $this->getJson(offersUrl($fixture['product']->uuid))->assertOk()
        ->assertJsonPath('data.featured.store.city', null);
});

it('opens the buy box by SLUG as well as by uuid, and 404s anything else', function (): void {
    $fixture = publiclyOfferedProduct();

    Offer::factory()->priced(9_990)->forVariant('v-1', $fixture['product']->uuid)
        ->forStore($fixture['store'])->create();

    /*
     * THE FOURTH APPEARANCE OF ONE BUG. `/products/{slug}/offers` is a public
     * storefront URL and the flat scheme (ADR-059) means the segment is usually a
     * slug — but the slug went straight into a `product_uuid` comparison, which on
     * PostgreSQL is SQLSTATE[22P02] and a 500 on the buy box, while on SQLite it
     * is a silent false. The three before it: ADR-049's reservation reference,
     * the ADR-056 geo cascade, ADR-059's own listing filter.
     *
     * Offer may not import Catalog's slug registry, so the resolution goes
     * through `CatalogBrowseContract` — the port it already asks for this
     * product's title.
     */
    $bySlug = $this->getJson(offersUrl($fixture['product']->slug))->assertOk()->json('data');
    $byUuid = $this->getJson(offersUrl($fixture['product']->uuid))->assertOk()->json('data');

    expect($bySlug)->toBe($byUuid)
        ->and($bySlug['featured'])->not->toBeNull();
});

it('404s an unknown slug on the buy box rather than casting it to a uuid', function (): void {
    publiclyOfferedProduct();

    // A miss is a miss on every one of these, and none of them is a 500 — the
    // property the guard exists for.
    foreach (['kesinlikle-boyle-bir-urun-yok', 'not-a-uuid', (string) Str::uuid()] as $segment) {
        $this->getJson(offersUrl($segment))->assertNotFound();
    }
});

it('refuses a category or brand slug on the product buy box', function (): void {
    $fixture = publiclyOfferedProduct();

    Offer::factory()->priced(9_990)->forVariant('v-1', $fixture['product']->uuid)
        ->forStore($fixture['store'])->create();

    $brand = Brand::factory()->create(['name' => 'Bir Marka', 'slug' => 'bir-marka']);

    // Resolvable, and not a product. Answering with one would let
    // `/products/{brandSlug}/offers` render somebody else's buy box.
    $this->getJson(offersUrl($brand->slug))->assertNotFound();
    $this->getJson(offersUrl($fixture['product']->category->slug))->assertNotFound();
});

it('needs no authentication', function (): void {
    $fixture = publiclyOfferedProduct();
    Offer::factory()->forVariant('v-1', $fixture['product']->uuid)
        ->forStore($fixture['store'])->create();

    // No acting-as anywhere in this file. Stated as its own test because the
    // whole surface's reason for existing is that a shopper is not logged in.
    $this->getJson(offersUrl($fixture['product']->uuid))->assertOk();
});

/*
|--------------------------------------------------------------------------
| The decimal-string conversion itself
|--------------------------------------------------------------------------
|
| String arithmetic, not `$minor / 100` — a float is exactly what the whole
| convention exists to avoid.
*/

it('converts minor units to a decimal string without touching a float', function (): void {
    expect(MoneyString::from(129_900))->toBe('1299.00')
        ->and(MoneyString::from(12_990))->toBe('129.90')
        ->and(MoneyString::from(5))->toBe('0.05')
        ->and(MoneyString::from(0))->toBe('0.00')
        ->and(MoneyString::from(-12_990))->toBe('-129.90')
        // A zero-decimal currency (JPY-style) renders no separator at all.
        ->and(MoneyString::from(1_299, 0))->toBe('1299')
        // And a three-decimal one (KWD-style) keeps all three.
        ->and(MoneyString::from(1_299, 3))->toBe('1.299');
});
