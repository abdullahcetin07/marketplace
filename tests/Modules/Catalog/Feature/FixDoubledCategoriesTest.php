<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| catalog:fix-doubled-categories
|--------------------------------------------------------------------------
|
| The bulk import (ADR-074) created categories with their own name welded to
| itself — `magnezyum-bisglisinatmagnezyum-bisglisinat` — at the ROOT of the
| tree, beside the correctly-named category they duplicate. Nine of them held
| 512 products on the live catalogue: a shopper saw them in the menu and the
| Merchant feed had to name four of the slugs by hand to keep supplements out.
|
| Two properties carry the risk here, so both are asserted rather than
| described: products move through the AUTHORING action (so ADR-047 is
| re-checked and Scout hears the save), and a twin a curator has closed is
| left alone rather than tidied into.
|
*/

/**
 * An impostor at the root plus the real category it duplicates.
 *
 * @return array{0: Category, 1: Category}
 */
function doubledPair(string $slug, int $products = 2): array
{
    $parent = Category::factory()->create(['slug' => $slug.'-parent']);
    $twin = Category::factory()->childOf($parent)->create(['slug' => $slug]);
    $broken = Category::factory()->create(['slug' => $slug.$slug, 'parent_id' => null]);

    Product::factory()->count($products)->for($broken, 'category')->create();

    return [$broken, $twin];
}

it('reports the merge without writing anything by default', function (): void {
    [$broken, $twin] = doubledPair('magnezyum');

    $this->artisan('catalog:fix-doubled-categories')
        ->expectsOutputToContain('magnezyummagnezyum')
        ->assertSuccessful();

    expect(Category::query()->find($broken->getKey()))->not->toBeNull()
        ->and($twin->products()->count())->toBe(0);
});

it('moves the products to the twin and deletes the impostor', function (): void {
    // TWO products minimum: strict mode only arms the lazy-loading guard past
    // one row, so a single-product fixture proves less than it looks.
    [$broken, $twin] = doubledPair('d3-k2-vitamini', products: 3);

    $this->artisan('catalog:fix-doubled-categories --apply --force')->assertSuccessful();

    expect(Category::query()->find($broken->getKey()))->toBeNull()
        ->and($twin->products()->count())->toBe(3)
        ->and(Category::query()->where('slug', 'like', '%d3-k2-vitanid3%')->exists())->toBeFalse();
});

it('saves each moved product as a model, which is what search listens to', function (): void {
    /*
    | The ADR-074/076 rule, asserted rather than trusted: a query-builder
    | `update(['category_id' => …])` would leave every moved product right in
    | the table and stale in Scout, the storefront and the feed. Eloquent's
    | `saved` event is exactly what the Searchable trait hooks.
    */
    doubledPair('kolajen', products: 2);

    Event::fake(['eloquent.saved: '.Product::class]);

    $this->artisan('catalog:fix-doubled-categories --apply --force')->assertSuccessful();

    Event::assertDispatched('eloquent.saved: '.Product::class);
});

it('refuses to move products into a twin a curator has closed', function (): void {
    /*
    | `accepts_products = false` is a human decision (ADR-047). Tidying a slug
    | is not a reason to overrule it, so the impostor SURVIVES rather than
    | having its products forced somewhere they were deliberately barred.
    */
    $parent = Category::factory()->create(['slug' => 'omega-parent']);
    $twin = Category::factory()->childOf($parent)->container()->create(['slug' => 'omega']);
    $broken = Category::factory()->create(['slug' => 'omegaomega', 'parent_id' => null]);
    Product::factory()->count(2)->for($broken, 'category')->create();

    $this->artisan('catalog:fix-doubled-categories --apply --force')->assertFailed();

    expect(Category::query()->find($broken->getKey()))->not->toBeNull()
        ->and($broken->products()->count())->toBe(2)
        ->and($twin->fresh()->accepts_products)->toBeFalse();
});

it('leaves a doubled slug with no twin alone', function (): void {
    $orphan = Category::factory()->create(['slug' => 'yalnizyalniz', 'parent_id' => null]);
    Product::factory()->count(2)->for($orphan, 'category')->create();

    $this->artisan('catalog:fix-doubled-categories --apply --force')->assertFailed();

    expect(Category::query()->find($orphan->getKey()))->not->toBeNull();
});

it('changes nothing on a second run', function (): void {
    doubledPair('probiyotik', products: 2);

    $this->artisan('catalog:fix-doubled-categories --apply --force')->assertSuccessful();

    $categories = Category::query()->count();
    $products = Product::query()->count();

    $this->artisan('catalog:fix-doubled-categories --apply --force')
        ->expectsOutputToContain('Nothing to fix.')
        ->assertSuccessful();

    expect(Category::query()->count())->toBe($categories)
        ->and(Product::query()->count())->toBe($products);
});

it('re-parents the supplement aisle the import left at the root', function (): void {
    /*
    | Not a merge — there is no twin — and its products cannot go up to
    | `besin-takviyeleri`, which is `accepts_products = false` on the live
    | catalogue. So the CATEGORY moves and the products come with it.
    */
    $supplements = Category::factory()->container()->create([
        'slug' => 'besin-takviyeleri',
        'parent_id' => null,
    ]);
    $stray = Category::factory()->create([
        'slug' => 'takviye-edici-gida-urunleri',
        'parent_id' => null,
    ]);
    Product::factory()->count(2)->for($stray, 'category')->create();

    $this->artisan('catalog:fix-doubled-categories --apply --force')->assertSuccessful();

    expect($stray->fresh()->parent_id)->toBe($supplements->getKey())
        ->and($stray->fresh()->products()->count())->toBe(2);
});
