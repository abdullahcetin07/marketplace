<?php

declare(strict_types=1);

use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| Every live shop's slug, for the sitemap
|--------------------------------------------------------------------------
|
| The storefront could not enumerate `/magaza/*`, so those pages were absent from
| the sitemap entirely. The one rule that matters here is that this list and the
| store page agree about what "live" means: advertising a URL that 404s teaches a
| crawler that the site promises pages it does not serve.
|
| It answers at `/api/v1/magazalar`, not `/api/v1/stores` — that URI already belongs
| to the seller panel's own-shops list, and two routes sharing a method and URI do
| not both survive registration.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

it('lists live shops with a timestamp the sitemap can stamp as lastmod', function (): void {
    $live = Store::factory()->create(['status' => StoreStatus::Active]);

    $response = $this->getJson('/api/v1/magazalar')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.slug'))->toBe($live->slug)
        // A catalogue whose prices move daily has freshness to declare; a sitemap
        // without `lastmod` declares none of it.
        ->and($response->json('data.0.updated_at'))->toBeString();
});

it('leaves out every shop the store page would 404', function (): void {
    Store::factory()->create(['status' => StoreStatus::Active]);

    foreach ([StoreStatus::Draft, StoreStatus::Paused, StoreStatus::Suspended, StoreStatus::Closed] as $status) {
        Store::factory()->create(['status' => $status]);
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $this->getJson('/api/v1/magazalar')->assertOk()->json('data');

    /*
     * **THE SAME VISIBILITY RULE, NOT A SIMILAR ONE.** Both this list and the store
     * page read `scopePubliclyVisible()`; a second definition here is how a sitemap
     * starts advertising soft-404s.
     */
    expect(array_column($rows, 'slug'))->toHaveCount(1);
});

it('is readable without signing in, and carries nothing but slugs and dates', function (): void {
    Store::factory()->create(['status' => StoreStatus::Active]);

    $row = $this->getJson('/api/v1/magazalar')->assertOk()->json('data.0');

    // A sitemap needs an address and a date. Names and branding are on the pages
    // themselves, in the same number of requests a scraper would spend anyway.
    expect(array_keys($row))->toEqualCanonicalizing(['slug', 'updated_at']);
});
