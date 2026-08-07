<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| What a stranger reads (ADR-069)
|--------------------------------------------------------------------------
|
| This is the platform's first surface that publishes one user's words to every
| other user, so two properties carry the whole file:
|
|  1. **ONLY PUBLISHED REVIEWS EXIST HERE.** Not in the list, not in the average,
|     not in a distribution bucket, not in `with_images_count`. The moment one
|     leaks, a moderator's decision has stopped meaning anything.
|  2. **THE AUTHOR IS A MASKED NAME AND NOTHING ELSE.** No customer id, no
|     customer uuid, no order line — a uuid on this surface would let anyone
|     rebuild one person's shopping history from public data.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A published product with a live shop, ready to be reviewed.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{product: Product, store: Store}
 */
function reviewedProductFixture(): array
{
    $category = Category::factory()->childOf(Category::factory()->create())->create();

    return [
        'product' => Product::factory()->for($category, 'category')->published()
            ->create(['title_tr' => 'Pamuklu Tişört', 'title_en' => 'Cotton T-Shirt']),
        'store' => Store::factory()->create(['status' => StoreStatus::Active, 'name' => 'Deniz Kozmetik']),
    ];
}

function reviewsUrl(string $product, string $query = ''): string
{
    return '/api/v1/products/'.$product.'/reviews'.($query === '' ? '' : '?'.$query);
}

it('shows published reviews and hides everything else', function (): void {
    $fixture = reviewedProductFixture();
    $product = $fixture['product']->uuid;

    $live = Review::factory()->forProduct($product)->forStore($fixture['store']->uuid)
        ->published()->withRating(5)->create(['body' => 'Harika']);

    Review::factory()->forProduct($product)->pending()->withRating(1)->create();
    Review::factory()->forProduct($product)->rejected()->withRating(1)->create();

    $response = $this->getJson(reviewsUrl($product))->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $live->uuid)
        ->assertJsonPath('data.0.body', 'Harika')
        /*
         * THE PENDING 1-STAR IS INVISIBLE IN BOTH PLACES — the list AND the
         * average. A summary that counted it would let an unmoderated review
         * damage a product before anybody had read it, which is the whole thing
         * pre-moderation exists to stop.
         */
        ->assertJsonPath('meta.summary.average', '5.0')
        ->assertJsonPath('meta.summary.count', 1);
});

it('never exposes who wrote it beyond a masked name', function (): void {
    $fixture = reviewedProductFixture();
    $product = $fixture['product']->uuid;

    Review::factory()->forProduct($product)->forStore($fixture['store']->uuid)->published()
        ->forCustomer(42, 'gizli-musteri-uuid')->create([
            'author_name' => 'Abdullah Ç.',
            'order_line_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

    $response = $this->getJson(reviewsUrl($product))->assertOk();

    $response->assertJsonPath('data.0.author_name', 'Abdullah Ç.')
        // The masked name is the WHOLE identity on this surface.
        ->assertJsonMissing(['customer_id' => 42])
        ->assertJsonMissing(['customer_uuid' => 'gizli-musteri-uuid'])
        // The purchase behind the review is the platform's proof, not a public
        // handle to somebody's order.
        ->assertJsonMissing(['order_line_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

    $body = $response->json();
    expect(json_encode($body))->not->toContain('gizli-musteri-uuid')
        ->and(json_encode($body))->not->toContain('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
});

it('names the shop each review was bought from', function (): void {
    $fixture = reviewedProductFixture();

    Review::factory()->forProduct($fixture['product']->uuid)->forStore($fixture['store']->uuid)
        ->published()->create();

    /*
     * THE NAME COMES THROUGH `StoreQueryContract` — Reviews may not import Store
     * — batched for the whole page, because one call per row would be a query
     * per merchant on a public product page.
     */
    $this->getJson(reviewsUrl($fixture['product']->uuid))->assertOk()
        ->assertJsonPath('data.0.seller.id', $fixture['store']->uuid)
        ->assertJsonPath('data.0.seller.name', 'Deniz Kozmetik')
        ->assertJsonPath('data.0.seller.slug', $fixture['store']->slug);
});

it('filters by seller, by photos and by rating', function (): void {
    $fixture = reviewedProductFixture();
    $product = $fixture['product']->uuid;
    $otherShop = (string) Str::uuid();

    Review::factory()->forProduct($product)->forStore($fixture['store']->uuid)->published()
        ->withRating(5)->withPhotos()->create();
    Review::factory()->forProduct($product)->forStore($otherShop)->published()
        ->withRating(3)->create();

    $this->getJson(reviewsUrl($product, 'seller='.$fixture['store']->uuid))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.rating', 5);

    $this->getJson(reviewsUrl($product, 'with_images=1'))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.rating', 5);

    $this->getJson(reviewsUrl($product, 'rating=3'))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.rating', 3);
});

it('keeps the summary about the whole product while the list is filtered', function (): void {
    $fixture = reviewedProductFixture();
    $product = $fixture['product']->uuid;

    Review::factory()->forProduct($product)->forStore($fixture['store']->uuid)->published()->withRating(5)->create();
    Review::factory()->forProduct($product)->forStore((string) Str::uuid())->published()->withRating(1)->create();

    $response = $this->getJson(reviewsUrl($product, 'seller='.$fixture['store']->uuid))->assertOk();

    /*
     * **THE STAR BLOCK MUST NOT JUMP WHEN SOMEBODY TICKS A BOX.** The filter
     * narrows what a shopper READS, not what the product IS — so the list is one
     * review and the average is still the product's 3.0 across both.
     */
    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.summary.average', '3.0')
        ->assertJsonPath('meta.summary.count', 2);
});

it('carries a distribution with every bucket and the seller breakdown', function (): void {
    $fixture = reviewedProductFixture();
    $product = $fixture['product']->uuid;

    Review::factory()->forProduct($product)->forStore($fixture['store']->uuid)->published()
        ->withRating(5)->count(2)->create();
    Review::factory()->forProduct($product)->forStore($fixture['store']->uuid)->published()
        ->withRating(4)->withPhotos()->create();

    $response = $this->getJson(reviewsUrl($product))->assertOk();

    $response->assertJsonPath('meta.summary.distribution', [5 => 2, 4 => 1, 3 => 0, 2 => 0, 1 => 0])
        ->assertJsonPath('meta.summary.with_images_count', 1)
        // The breakdown doubles as the seller filter's option list — a shopper
        // cannot type a store uuid.
        ->assertJsonPath('meta.summary.sellers.0.id', $fixture['store']->uuid)
        ->assertJsonPath('meta.summary.sellers.0.name', 'Deniz Kozmetik')
        ->assertJsonPath('meta.summary.sellers.0.count', 3);
});

it('opens by slug as well as by uuid, and 404s anything else', function (): void {
    $fixture = reviewedProductFixture();

    Review::factory()->forProduct($fixture['product']->uuid)->published()->create();

    /*
     * A SLUG NEVER REACHES A UUID COLUMN (ADR-059). The segment is resolved
     * through `CatalogBrowseContract` before anything is queried — without it
     * this is SQLSTATE[22P02] on PostgreSQL, a 500 on a public page, which the
     * platform has shipped more than once.
     */
    $this->getJson(reviewsUrl($fixture['product']->slug))->assertOk()->assertJsonCount(1, 'data');

    // "No such product", "not published" and "that is not a uuid" are one answer.
    $this->getJson(reviewsUrl('boyle-bir-urun-yok'))->assertNotFound();
    $this->getJson(reviewsUrl((string) Str::uuid()))->assertNotFound();
});

it('answers an unreviewed product with an empty list and a zero summary', function (): void {
    $fixture = reviewedProductFixture();

    $this->getJson(reviewsUrl($fixture['product']->uuid))->assertOk()
        ->assertJsonCount(0, 'data')
        // Asked directly, "what is this product's average" is honestly 0.0 over
        // nothing — the BATCH endpoint is where absence is the right answer.
        ->assertJsonPath('meta.summary.average', '0.0')
        ->assertJsonPath('meta.summary.count', 0);
});

/*
|--------------------------------------------------------------------------
| The batch endpoint that feeds listing-card stars
|--------------------------------------------------------------------------
*/

it('prices a grid of stars in one call and omits the unrated', function (): void {
    $rated = (string) Str::uuid();
    $unrated = (string) Str::uuid();

    Review::factory()->forProduct($rated)->published()->withRating(4)->count(2)->create();
    Review::factory()->forProduct($rated)->pending()->withRating(1)->create();

    $response = $this->postJson('/api/v1/products/ratings', [
        'product_ids' => [$rated, $unrated],
    ])->assertOk();

    $response->assertJsonPath("data.ratings.{$rated}.average", '4.0')
        ->assertJsonPath("data.ratings.{$rated}.count", 2);

    /*
     * **THE UNRATED PRODUCT IS ABSENT, NOT ZERO.** A card handed `0.0` renders
     * "★ 0.0", which a shopper reads as "rated badly" rather than "not rated
     * yet" — omission is what triggers the client's own no-rating branch.
     */
    expect($response->json('data.ratings'))->not->toHaveKey($unrated);
});

it('caps the batch and refuses a non-uuid in it', function (): void {
    // Uncapped, this is a denial-of-service written as a feature: an
    // unauthenticated request asking the platform to group over everything.
    $this->postJson('/api/v1/products/ratings', [
        'product_ids' => array_map(static fn (): string => (string) Str::uuid(), range(1, 101)),
    ])->assertStatus(422);

    $this->postJson('/api/v1/products/ratings', ['product_ids' => ['not-a-uuid']])
        ->assertStatus(422);
});
