<?php

declare(strict_types=1);

use App\Modules\Reviews\Domain\Contracts\ReviewRepositoryContract;
use App\Modules\Reviews\Domain\DTOs\ReviewListFilterDTO;
use App\Modules\Reviews\Domain\Models\Review;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The read model over published reviews (ADR-069)
|--------------------------------------------------------------------------
|
| Two properties matter more than any individual query:
|
|  1. **UNPUBLISHED REVIEWS DO NOT EXIST** to any public read. A pending review
|     is not listed, not averaged, not counted in a distribution and not counted
|     as "with images". The moment one of those leaks, a moderator's decision has
|     stopped meaning anything.
|  2. **THE AVERAGE IS A DECIMAL STRING.** Not money — the minor-units rule does
|     not apply here — but it crosses to a client the same way money does, and
|     "4.3" is not representable as a float.
|
*/

beforeEach(function (): void {
    $this->reviews = app(ReviewRepositoryContract::class);
    $this->product = (string) Str::uuid();
});

it('averages only published reviews, and ignores the rest entirely', function (): void {
    $product = $this->product;

    // 5 + 4 = 9 over two published reviews → 4.5.
    Review::factory()->forProduct($product)->published()->withRating(5)->create();
    Review::factory()->forProduct($product)->published()->withRating(4)->create();

    /*
     * THE TWO THAT MUST NOT COUNT. A pending 1-star would drag the average to
     * 3.33 before a moderator had even read it — which is exactly the outcome
     * pre-moderation exists to prevent — and a rejected one would let a refused
     * review damage a product anyway.
     */
    Review::factory()->forProduct($product)->pending()->withRating(1)->create();
    Review::factory()->forProduct($product)->rejected()->withRating(1)->create();

    $summary = $this->reviews->summaryForProduct($product);

    expect($summary['average'])->toBe('4.5')
        ->and($summary['average'])->toBeString()
        ->and($summary['count'])->toBe(2)
        ->and($summary['distribution'])->toBe([5 => 1, 4 => 1, 3 => 0, 2 => 0, 1 => 0]);
});

it('fills every distribution bucket, and they sum to the count', function (): void {
    $product = $this->product;

    Review::factory()->forProduct($product)->published()->withRating(5)->count(3)->create();
    Review::factory()->forProduct($product)->published()->withRating(2)->create();

    $summary = $this->reviews->summaryForProduct($product);

    /*
     * THE EMPTY BUCKETS ARE PRESENT AND ZERO. A bar chart missing 4, 3 and 1 is
     * a bar chart the client has to repair, and every client would repair it
     * slightly differently.
     */
    expect($summary['distribution'])->toBe([5 => 3, 4 => 0, 3 => 0, 2 => 1, 1 => 0])
        ->and(array_sum($summary['distribution']))->toBe($summary['count'])
        ->and($summary['average'])->toBe('4.3'); // 17 / 4 = 4.25 → 4.3
});

it('answers zero for an unreviewed product, and the batch read stays silent about it', function (): void {
    $unreviewed = (string) Str::uuid();

    /*
     * **THE SAME QUESTION, TWO DIFFERENT RIGHT ANSWERS.** Asked directly, "what
     * is this product's average" is honestly 0.0 over 0 reviews. Asked as part
     * of a grid, the product must be ABSENT — a card handed `0.0` renders
     * "★ 0.0", which a shopper reads as "rated badly" rather than "not rated
     * yet". The difference is the whole reason the batch method exists rather
     * than a loop over the other one.
     */
    expect($this->reviews->summaryForProduct($unreviewed))
        ->toMatchArray(['average' => '0.0', 'count' => 0, 'with_images_count' => 0]);

    expect($this->reviews->summariesForProducts([$unreviewed]))->toBe([]);
});

it('prices a whole grid in one map, published only', function (): void {
    $a = (string) Str::uuid();
    $b = (string) Str::uuid();

    Review::factory()->forProduct($a)->published()->withRating(5)->count(2)->create();
    Review::factory()->forProduct($b)->published()->withRating(3)->create();
    Review::factory()->forProduct($b)->pending()->withRating(1)->create();

    $map = $this->reviews->summariesForProducts([$a, $b, (string) Str::uuid()]);

    expect($map)->toHaveCount(2)
        ->and($map[$a])->toBe(['average' => '5.0', 'count' => 2])
        // The pending 1-star is invisible here too.
        ->and($map[$b])->toBe(['average' => '3.0', 'count' => 1]);
});

it('counts and filters the ones with photos', function (): void {
    $product = $this->product;

    Review::factory()->forProduct($product)->published()->withPhotos()->create();
    Review::factory()->forProduct($product)->published()->create();
    // Pending WITH photos — not counted, not listed.
    Review::factory()->forProduct($product)->pending()->withPhotos()->create();

    expect($this->reviews->summaryForProduct($product)['with_images_count'])->toBe(1);

    $page = $this->reviews->publishedForProduct($product, new ReviewListFilterDTO(withImages: true));

    expect($page->total())->toBe(1);
});

it('breaks the summary down by the seller each review was bought from', function (): void {
    $product = $this->product;
    $shopA = (string) Str::uuid();
    $shopB = (string) Str::uuid();

    Review::factory()->forProduct($product)->forStore($shopA)->published()->count(2)->create();
    Review::factory()->forProduct($product)->forStore($shopB)->published()->create();

    $summary = $this->reviews->summaryForProduct($product);

    /*
     * ONE PRODUCT, EVERY SELLER'S BUYERS (ADR-066). The catalogue is shared, so
     * "bu satıcıdan alanlar ne demiş" is a FILTER on this one set — which is why
     * the breakdown lives on the product's summary rather than there being a
     * separate per-seller summary to keep in step with it.
     */
    expect($summary['sellers'])->toBe([
        ['store_uuid' => $shopA, 'count' => 2],
        ['store_uuid' => $shopB, 'count' => 1],
    ]);

    $filtered = $this->reviews->publishedForProduct($product, new ReviewListFilterDTO(sellerStoreUuid: $shopB));

    expect($filtered->total())->toBe(1);
});

it('remembers which purchases a customer has already reviewed, pending included', function (): void {
    $product = $this->product;
    $lineA = (string) Str::uuid();
    $lineB = (string) Str::uuid();

    Review::factory()->forProduct($product)->forCustomer(7, 'musteri')->published()
        ->create(['order_line_uuid' => $lineA]);
    Review::factory()->forProduct($product)->forCustomer(7, 'musteri')->pending()
        ->create(['order_line_uuid' => $lineB]);
    // Somebody else's, and a different product's — neither is this customer's.
    Review::factory()->forProduct($product)->forCustomer(8, 'baskasi')->published()->create();
    Review::factory()->forCustomer(7, 'musteri')->published()->create();

    $reviewed = $this->reviews->reviewedOrderLineUuids(7, $product);

    /*
     * **THE PENDING ONE COUNTS, AND THAT IS A CORRECTNESS RULE.** This list is
     * what the eligibility read subtracts; offering a line back while its review
     * waits for a moderator would let the buyer submit a second one and meet the
     * unique index as a 500 instead of a tidy refusal.
     */
    expect($reviewed)->toHaveCount(2)
        ->and($reviewed)->toContain($lineA)
        ->and($reviewed)->toContain($lineB);
});

it('refuses a second review of the same delivered line, at the database', function (): void {
    $line = (string) Str::uuid();

    Review::factory()->create(['order_line_uuid' => $line]);

    /*
     * **THE UNIQUE INDEX IS THE INTEGRITY MODEL** (ADR-067), not the action's
     * check in front of it. One delivered line, at most one review — while a
     * buyer who bought the same product in two ORDERS gets two, which is why the
     * uniqueness sits on the line rather than on (customer, product).
     */
    expect(static fn (): Review => Review::factory()->create(['order_line_uuid' => $line]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('hands a buyer back their own reviews in every status', function (): void {
    Review::factory()->forCustomer(7, 'musteri')->published()->create();
    Review::factory()->forCustomer(7, 'musteri')->pending()->create();
    Review::factory()->forCustomer(7, 'musteri')->rejected()->create();
    Review::factory()->forCustomer(8, 'baskasi')->published()->create();

    // ALL THREE (Reviews.md §8): a buyer who cannot see their pending review
    // writes it again believing it was lost.
    expect($this->reviews->forCustomer(7))->toHaveCount(3);
});

it('frees the line when a review is deleted', function (): void {
    $line = (string) Str::uuid();
    $review = Review::factory()->create(['order_line_uuid' => $line]);

    $this->reviews->delete($review);

    /*
     * A HARD DELETE, and the freed line is the reason. A soft delete would leave
     * a ghost row colliding with the unique index, so a buyer who deleted a
     * mistaken review could never write the right one — and Reviews keeps no
     * audit trail of its own anyway (that is Audit's job).
     */
    expect(Review::query()->count())->toBe(0)
        ->and(Review::factory()->create(['order_line_uuid' => $line])->exists)->toBeTrue();
});
