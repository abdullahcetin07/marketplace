<?php

declare(strict_types=1);

use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| A shop's rating on its own page (SEO audit #4, ADR-036/066)
|--------------------------------------------------------------------------
|
| Reviews are about the PRODUCT and carry the seller they were bought from as a
| tag copied from the delivered order line. This rollup is those same reviews seen
| from the shop's side — it does not create a way to review a seller.
|
| The storefront renders stars from it and puts it in the store's Organization
| JSON-LD, so the one rule that cannot bend is: no reviews, no rating. Structured
| data that invents one is what search engines penalise.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A live store and the organization behind it.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{store: Store, org: Organization}
 */
function ratedStore(): array
{
    $org = Organization::factory()->create();

    $store = Store::factory()->create([
        'organization_id' => $org->getKey(),
        'status' => StoreStatus::Active,
    ]);

    return ['store' => $store, 'org' => $org];
}

it('rolls the shop’s published reviews up onto its page', function (): void {
    $fixture = ratedStore();

    foreach ([5, 4, 4] as $rating) {
        Review::factory()->create([
            'selling_org_uuid' => $fixture['org']->uuid,
            'status' => ReviewStatus::Published,
            'rating' => $rating,
        ]);
    }

    $rating = $this->getJson('/api/v1/magaza/'.$fixture['store']->slug)
        ->assertOk()
        ->json('data.extensions.rating');

    // 13 / 3 = 4.333… → one decimal, which is all a star row can show.
    expect((float) $rating['value'])->toBe(4.3)
        ->and($rating['count'])->toBe(3);
});

it('counts only what a moderator published', function (): void {
    $fixture = ratedStore();

    Review::factory()->create([
        'selling_org_uuid' => $fixture['org']->uuid,
        'status' => ReviewStatus::Published,
        'rating' => 5,
    ]);

    foreach ([ReviewStatus::PendingReview, ReviewStatus::Rejected] as $status) {
        Review::factory()->create([
            'selling_org_uuid' => $fixture['org']->uuid,
            'status' => $status,
            'rating' => 1,
        ]);
    }

    $rating = $this->getJson('/api/v1/magaza/'.$fixture['store']->slug)
        ->assertOk()
        ->json('data.extensions.rating');

    /*
     * A pending review is one nobody has read and a rejected one the platform
     * refused (ADR-068). Counting either would let a shop's public stars move on
     * text no buyer will ever see.
     */
    expect((float) $rating['value'])->toBe(5.0)
        ->and($rating['count'])->toBe(1);
});

it('shows no rating at all for a shop nobody has reviewed', function (): void {
    $fixture = ratedStore();

    $rating = $this->getJson('/api/v1/magaza/'.$fixture['store']->slug)
        ->assertOk()
        ->json('data.extensions.rating');

    /*
     * **NULL, NOT ZERO.** The storefront emits `aggregateRating` from this; "0.0
     * out of 5" is a claim about a shop nobody has judged, and inventing one in
     * structured data is what gets penalised. An explicit null rather than an
     * absent key, because the registry includes every section regardless — so this
     * is the only shape a client can branch on.
     */
    expect($rating['value'])->toBeNull()
        ->and($rating['count'])->toBe(0);
});

it('does not lend one shop’s reviews to another', function (): void {
    $mine = ratedStore();
    $theirs = ratedStore();

    Review::factory()->count(4)->create([
        'selling_org_uuid' => $theirs['org']->uuid,
        'status' => ReviewStatus::Published,
        'rating' => 5,
    ]);

    $rating = $this->getJson('/api/v1/magaza/'.$mine['store']->slug)
        ->assertOk()
        ->json('data.extensions.rating');

    expect($rating['value'])->toBeNull()
        ->and($rating['count'])->toBe(0);
});
