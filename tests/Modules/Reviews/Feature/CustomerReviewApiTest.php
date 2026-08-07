<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The buyer's own surfaces (Reviews.md §8)
|--------------------------------------------------------------------------
|
| END TO END, THROUGH THE REAL ORDER PORT — no fake here, unlike `ReviewGateTest`.
| That file proves the action's guard in isolation; this one proves the whole
| chain a shopper actually travels: a delivered order in `orders`, read through
| the Core contract, offered as an eligible line, turned into a pending review,
| and invisible on the public endpoint until somebody approves it.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A delivered purchase of a published product, by `$customer`.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{product: Product, order: Order, line: OrderLine, store: Store}
 */
function buyerReviewFixture(Customer $customer, string $storeName = 'Deniz Kozmetik'): array
{
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()
        ->create(['title_tr' => 'Pamuklu Tişört', 'title_en' => 'Cotton T-Shirt']);

    // Store names are UNIQUE platform-wide (the onboarding reflow's rule), so a
    // test building two fixtures has to name the second one.
    $store = Store::factory()->create(['status' => StoreStatus::Active, 'name' => $storeName]);

    /** @var Order $order */
    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
        'store_uuid' => $store->uuid,
        'status' => OrderStatus::Delivered,
        'placed_at' => now()->subDays(5),
    ]);

    $line = OrderLine::factory()->for($order)->create([
        'product_uuid' => $product->uuid,
        'product_title' => 'Pamuklu Tişört',
        'variant_label' => 'Mavi / M',
    ]);

    return ['product' => $product, 'order' => $order, 'line' => $line, 'store' => $store];
}

it('offers a delivered purchase, named so the buyer knows which one', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = buyerReviewFixture($customer);

    $response = $this->getJson('/api/v1/reviews/eligible?product='.$fixture['product']->uuid)->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.order_line_uuid', $fixture['line']->uuid)
        /*
         * THE PURCHASE IS NAMED, not just offered. A shopper who bought the same
         * thing twice has to know which order this review is about — which is the
         * whole reason the port returns lines rather than a boolean.
         */
        ->assertJsonPath('data.0.product_title', 'Pamuklu Tişört')
        ->assertJsonPath('data.0.variant_label', 'Mavi / M')
        ->assertJsonPath('data.0.seller.name', 'Deniz Kozmetik')
        ->assertJsonPath('data.0.seller.id', $fixture['store']->uuid);
});

it('writes a pending review and keeps it off the public page', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = buyerReviewFixture($customer);

    $response = $this->postJson('/api/v1/reviews', [
        'product' => $fixture['product']->uuid,
        'order_line_uuid' => $fixture['line']->uuid,
        'rating' => 5,
        'body' => 'Kumaşı çok iyi.',
    ])->assertCreated();

    /*
     * **THE 201 SAYS `pending_review`, AND THAT IS WHY THE RESOURCE IS RETURNED
     * AT ALL** (ADR-068). The UI has to say "onay bekliyor" rather than
     * congratulating somebody on a review nobody can read.
     */
    $response->assertJsonPath('data.status', ReviewStatus::PendingReview->value)
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.product_title', $fixture['product']->localized('title'));

    // Invisible to everyone else until a moderator publishes it.
    $this->getJson('/api/v1/products/'.$fixture['product']->uuid.'/reviews')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.summary.count', 0);

    // And the seller tag came from the ORDER, never from the request.
    expect(Review::query()->firstOrFail()->store_uuid)->toBe($fixture['store']->uuid);
});

it('signs the review with a masked name taken from the account', function (): void {
    $customer = $this->actingAsCustomer(
        Customer::factory()->create(['first_name' => 'Abdullah', 'last_name' => 'Çetin']),
    );
    $fixture = buyerReviewFixture($customer);

    $this->postJson('/api/v1/reviews', [
        'product' => $fixture['product']->uuid,
        'order_line_uuid' => $fixture['line']->uuid,
        'rating' => 4,
    ])->assertCreated();

    /*
     * MASKED AT THE SOURCE, NOT AT RENDER (Reviews.md §8). The column holds
     * "Abdullah Ç.", so no future surface can leak a full name by forgetting a
     * formatter — and it comes from the ACTOR, never the payload, so a client
     * cannot sign a review with somebody else's name.
     */
    expect(Review::query()->firstOrFail()->author_name)->toBe('Abdullah Ç.');
});

it('refuses a line that is not the buyer’s, and one that never arrived', function (): void {
    $customer = $this->actingAsCustomer();
    $mine = buyerReviewFixture($customer);
    $theirs = buyerReviewFixture(Customer::factory()->create(), 'Başka Dükkan');

    // Somebody else's delivered purchase.
    $this->postJson('/api/v1/reviews', [
        'product' => $theirs['product']->uuid,
        'order_line_uuid' => $theirs['line']->uuid,
        'rating' => 5,
    ])->assertStatus(422);

    // Their own order, but it has not been delivered.
    $mine['order']->forceFill(['status' => OrderStatus::Paid])->save();

    $this->postJson('/api/v1/reviews', [
        'product' => $mine['product']->uuid,
        'order_line_uuid' => $mine['line']->uuid,
        'rating' => 5,
    ])->assertStatus(422);

    expect(Review::query()->count())->toBe(0);
});

it('hides a purchase once it has been reviewed, and offers it again after a delete', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = buyerReviewFixture($customer);

    $created = $this->postJson('/api/v1/reviews', [
        'product' => $fixture['product']->uuid,
        'order_line_uuid' => $fixture['line']->uuid,
        'rating' => 3,
    ])->assertCreated()->json('data.id');

    $this->getJson('/api/v1/reviews/eligible?product='.$fixture['product']->uuid)
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->deleteJson('/api/v1/reviews/'.$created)->assertNoContent();

    /*
     * DELETE-AND-REWRITE IS THE EDIT STORY (§8). The hard delete frees the
     * `order_line_uuid`, so the buyer who posted the wrong thing can post the
     * right thing — which a soft delete's ghost row would have blocked forever.
     */
    $this->getJson('/api/v1/reviews/eligible?product='.$fixture['product']->uuid)
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('lists a buyer’s own reviews in every status, and nobody else’s', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = buyerReviewFixture($customer);

    Review::factory()->forCustomer((int) $customer->getKey(), $customer->uuid)
        ->forProduct($fixture['product']->uuid)->pending()->create();
    Review::factory()->forCustomer((int) $customer->getKey(), $customer->uuid)
        ->forProduct($fixture['product']->uuid)->rejected()->create();
    Review::factory()->forCustomer(999, 'baskasi')->published()->create();

    $response = $this->getJson('/api/v1/reviews/mine')->assertOk();

    /*
     * ALL THREE STATUSES (§8): a buyer who cannot see their pending review
     * believes it was lost and writes it again — and one who cannot see a
     * rejection waits forever.
     */
    $response->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('status')->sort()->values()->all())
        ->toBe(['pending_review', 'rejected']);
});

it('will not let one customer delete another’s review', function (): void {
    $this->actingAsCustomer();

    $theirs = Review::factory()->forCustomer(999, 'baskasi')->published()->create();

    $this->deleteJson('/api/v1/reviews/'.$theirs->uuid)->assertForbidden();

    expect(Review::query()->count())->toBe(1);
});

it('404s a review uuid that is malformed or unknown', function (): void {
    $this->actingAsCustomer();

    /*
     * BY SHAPE FIRST (ADR-059). `reviews.uuid` is a native uuid column on
     * PostgreSQL, so a malformed segment would be SQLSTATE[22P02] — a 500 on a
     * button the buyer taps — while SQLite quietly returns nothing.
     */
    $this->deleteJson('/api/v1/reviews/not-a-uuid')->assertNotFound();
    $this->deleteJson('/api/v1/reviews/'.Str::uuid()->toString())->assertNotFound();
});

it('attaches photos and marks the review as having them', function (): void {
    Storage::fake(config('marketplace.media.public_disk'));

    $customer = $this->actingAsCustomer();
    $fixture = buyerReviewFixture($customer);

    $this->postJson('/api/v1/reviews', [
        'product' => $fixture['product']->uuid,
        'order_line_uuid' => $fixture['line']->uuid,
        'rating' => 5,
        'photos' => [UploadedFile::fake()->image('urun.jpg')],
    ])->assertCreated();

    $review = Review::query()->firstOrFail();

    /*
     * **THE FLAG AND THE MEDIA ARE WRITTEN IN ONE ACTION**, so the denormalised
     * `has_photos` the product page filters on cannot drift from the photos that
     * justify it.
     */
    expect($review->has_photos)->toBeTrue()
        ->and($review->getMedia('images'))->toHaveCount(1);
});

it('keeps a seller and an anonymous visitor off the buyer’s endpoints', function (): void {
    $product = (string) Str::uuid();

    // Unauthenticated.
    $this->getJson('/api/v1/reviews/mine')->assertUnauthorized();

    $this->actingAs(App\Models\Seller::factory()->create(), 'seller');

    /*
     * A SELLER HAS NO PLACE HERE AT ALL. They cannot hold a delivered purchase
     * on the customer guard, and `SubmitReviewRequest` refuses any actor type
     * that is not a customer — the same rule ADR-068 states for moderation, from
     * the other end.
     */
    $this->postJson('/api/v1/reviews', [
        'product' => $product,
        'order_line_uuid' => (string) Str::uuid(),
        'rating' => 5,
    ])->assertForbidden();
});
