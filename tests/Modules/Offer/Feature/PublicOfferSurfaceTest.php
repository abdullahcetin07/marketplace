<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Support\MoneyString;
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
