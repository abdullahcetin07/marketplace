<?php

declare(strict_types=1);

use App\Core\Domain\Storefront\StorefrontContext;
use App\Core\Domain\Storefront\StorefrontContributorContract;
use App\Core\Support\StorefrontRegistry;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| Store — public storefront (ADR-034/035/036)
|--------------------------------------------------------------------------
|
| The canonical public entry point at /store/{slug} (+ localised segments).
| Only Active stores render; a strict allow-list; other modules compose in
| through the contributor registry, never by Store depending on them.
*/

/**
 * A stand-in for a future module (e.g. Reviews) that enriches the storefront.
 */
class FakeReviewsContributor implements StorefrontContributorContract
{
    public function key(): string
    {
        return 'reviews';
    }

    public function contribute(StorefrontContext $context): array
    {
        return ['count' => 3, 'store' => $context->slug];
    }
}

beforeEach(function (): void {
    $this->seedAll();
});

afterEach(function (): void {
    // Static registry must not leak a contributor into later tests.
    StorefrontRegistry::flush();
});

it('renders an active store as the public storefront (allow-list only)', function (): void {
    $store = Store::factory()->active()->create(['slug' => 'acme-goods', 'name' => 'Acme']);

    $response = $this->getJson('/api/v1/store/acme-goods');

    $response->assertOk()
        ->assertJsonPath('data.id', $store->uuid)
        ->assertJsonPath('data.slug', 'acme-goods')
        ->assertJsonPath('data.name', 'Acme')
        ->assertJsonStructure([
            'data' => [
                'id', 'slug', 'name',
                'locale' => ['language', 'currency', 'timezone'],
                'branding' => ['logo', 'banner', 'favicon', 'primary_color', 'accent_color', 'theme'],
                'seo' => ['meta_title', 'meta_description', 'meta_keywords', 'canonical_url', 'robots'],
                'contact' => ['email', 'phone', 'address', 'support_hours'],
                'extensions',
            ],
        ]);

    // No internal identifiers or private configuration ever leak.
    $data = $response->json('data');
    expect($data)->not->toHaveKey('organization_id')
        ->and($data)->not->toHaveKey('opening_request_uuid')
        ->and($data)->not->toHaveKey('settings')
        ->and($data)->not->toHaveKey('status')
        // The public id is the UUID, never the internal one.
        ->and($data['id'])->toBe($store->uuid);
});

it('404s a non-active store without disclosing that it exists', function (): void {
    Store::factory()->create(['slug' => 'draft-store', 'status' => StoreStatus::Draft]);

    $this->getJson('/api/v1/store/draft-store')->assertNotFound();
});

it('404s a slug that does not exist', function (): void {
    $this->getJson('/api/v1/store/no-such-store')->assertNotFound();
});

it('is reachable at every configured localised segment', function (): void {
    Store::factory()->active()->create(['slug' => 'acme']);

    $this->getJson('/api/v1/store/acme')->assertOk();
    $this->getJson('/api/v1/magaza/acme')->assertOk();
});

it('composes contributor sections into extensions without Store knowing them (ADR-036)', function (): void {
    StorefrontRegistry::register(FakeReviewsContributor::class);
    Store::factory()->active()->create(['slug' => 'with-reviews']);

    $this->getJson('/api/v1/store/with-reviews')
        ->assertOk()
        ->assertJsonPath('data.extensions.reviews.count', 3)
        ->assertJsonPath('data.extensions.reviews.store', 'with-reviews');
});
