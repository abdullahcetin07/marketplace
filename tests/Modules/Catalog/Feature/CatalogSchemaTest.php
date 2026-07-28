<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Enums\AttributeType;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\AttributeValue;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Organization\Domain\Models\Organization;
use Database\Modules\Catalog\Seeders\CatalogTaxonomySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Catalog — schema and persistence (Phase 1, P3)
|--------------------------------------------------------------------------
|
| These cover the guarantees the DATABASE makes, not the ones the actions make.
| The distinction matters: an action's check is a readable refusal, but the
| index underneath it is what holds when two requests race. Both are tested,
| here and in the action tests.
|
*/

/**
 * A real organization to hang provenance on.
 *
 * The `proposed_by_org_id` FK is real (integrity-only, ADR-033), so a test
 * cannot invent an id. Returned as a plain id+uuid pair because that is all
 * Catalog ever holds — the module imports no Organization model, and this
 * helper is the test suite's only contact with one.
 *
 * @return array{id: int, uuid: string}
 */
function proposingOrg(): array
{
    $organization = Organization::factory()->create();

    return ['id' => (int) $organization->getKey(), 'uuid' => (string) $organization->uuid];
}

it('materialises a category path from the parent chain', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create();

    expect($root->path)->toBe('/'.$root->id.'/')
        ->and($child->path)->toBe($root->path.$child->id.'/')
        ->and($grandchild->path)->toBe($child->path.$grandchild->id.'/')
        ->and($grandchild->depth)->toBe(2)
        ->and($grandchild->ancestorIds())->toBe([$root->id, $child->id]);
});

it('finds descendants with a single prefix scan', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create();
    $unrelated = Category::factory()->create();

    $descendants = Category::query()->descendantsOf($root)->pluck('id');

    expect($descendants)->toContain($child->id, $grandchild->id)
        ->and($descendants)->not->toContain($root->id)
        ->and($descendants)->not->toContain($unrelated->id);
});

it('treats a node with children as a container, never a leaf', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();

    expect($root->isLeaf())->toBeFalse()
        ->and($child->isLeaf())->toBeTrue()
        ->and(Category::query()->leaves()->pluck('id')->all())->toBe([$child->id]);
});

it('closes the path on both ends so one id cannot prefix-match another', function (): void {
    // The failure this guards: without the trailing separator, `/1/` matches
    // `/17/` and a whole unrelated branch reads as a descendant.
    $root = Category::factory()->create();

    expect($root->path)->toStartWith('/')->toEndWith('/');
});

it('keeps product and category slugs globally unique', function (): void {
    Category::factory()->create(['slug' => 'giyim']);

    expect(fn () => Category::factory()->create(['slug' => 'giyim']))
        ->toThrow(QueryException::class);
});

it('rejects a second product with the same GTIN', function (): void {
    // §3.4 — the shared catalog's dedup key. Two sellers proposing the same
    // manufactured product must collide here rather than both succeeding.
    $category = Category::factory()->create();
    Product::factory()->for($category, 'category')->create(['gtin' => '8691234567890']);

    expect(fn () => Product::factory()->for($category, 'category')->create(['gtin' => '8691234567890']))
        ->toThrow(QueryException::class);
});

it('allows many products with no GTIN at all', function (): void {
    // Plenty of real goods have no barcode; a NULL must not count as taken.
    $category = Category::factory()->create();
    Product::factory()->count(3)->for($category, 'category')->create(['gtin' => null]);

    expect(Product::query()->count())->toBe(3);
});

it('rejects a duplicate variant combination within one product', function (): void {
    // §3.3 — the index under the action's readable refusal.
    $product = Product::factory()->create();
    $key = ProductVariant::combinationKeyFor([1, 2]);

    ProductVariant::factory()->for($product)->create(['combination_key' => $key]);

    expect(fn () => ProductVariant::factory()->for($product)->create(['combination_key' => $key]))
        ->toThrow(QueryException::class);
});

it('allows the same combination on a different product', function (): void {
    $key = ProductVariant::combinationKeyFor([1, 2]);

    ProductVariant::factory()->for(Product::factory())->create(['combination_key' => $key]);
    ProductVariant::factory()->for(Product::factory())->create(['combination_key' => $key]);

    expect(ProductVariant::query()->where('combination_key', $key)->count())->toBe(2);
});

it('keeps SKUs unique across the whole catalog', function (): void {
    ProductVariant::factory()->create(['sku' => 'TSHIRT-ABC12345']);

    expect(fn () => ProductVariant::factory()->create(['sku' => 'TSHIRT-ABC12345']))
        ->toThrow(QueryException::class);
});

it('orders and de-duplicates a variant combination key', function (): void {
    // Member order must not produce a "different" variant, and a repeated id
    // must not smuggle a near-duplicate past the unique index.
    expect(ProductVariant::combinationKeyFor([5, 2, 9]))
        ->toBe(ProductVariant::combinationKeyFor([9, 5, 2]))
        ->and(ProductVariant::combinationKeyFor([2, 2, 5]))
        ->toBe(ProductVariant::combinationKeyFor([2, 5]))
        ->and(ProductVariant::combinationKeyFor([]))
        ->toBe(ProductVariant::NO_AXES_KEY);
});

it('binds an attribute to a category with per-category flags', function (): void {
    // §2.3 — the same attribute is a variant axis in one category and merely
    // descriptive in another; that is why the flags live on the binding.
    $clothing = Category::factory()->create();
    $furniture = Category::factory()->create();
    $colour = Attribute::factory()->create(['code' => 'renk']);

    $clothing->attributes()->attach($colour, ['is_variant_defining' => true, 'is_required' => true]);
    $furniture->attributes()->attach($colour, ['is_variant_defining' => false, 'is_required' => false]);

    // Read through wherePivot — the same narrowing AttributeRepository uses, so
    // this proves the query the integrity rules actually run, not just the row.
    expect($clothing->attributes()->wherePivot('is_variant_defining', true)->count())->toBe(1)
        ->and($furniture->attributes()->wherePivot('is_variant_defining', true)->count())->toBe(0)
        ->and($clothing->attributes()->wherePivot('is_required', true)->count())->toBe(1)
        ->and($furniture->attributes()->wherePivot('is_required', true)->count())->toBe(0);
});

it('allows only one binding per attribute per category', function (): void {
    $category = Category::factory()->create();
    $attribute = Attribute::factory()->create();

    $category->attributes()->attach($attribute);

    expect(fn () => $category->attributes()->attach($attribute))
        ->toThrow(QueryException::class);
});

it('allows only one value per attribute per product', function (): void {
    $product = Product::factory()->create();
    $attribute = Attribute::factory()->create();

    $product->attributes()->attach($attribute, ['value' => 'pamuk']);

    expect(fn () => $product->attributes()->attach($attribute, ['value' => 'yun']))
        ->toThrow(QueryException::class);
});

it('resolves localized text through the active locale then the fallback', function (): void {
    $product = Product::factory()->create(['title_tr' => 'Kırmızı Tişört', 'title_en' => 'Red T-Shirt']);

    expect($product->localized('title', 'tr'))->toBe('Kırmızı Tişört')
        ->and($product->localized('title', 'en'))->toBe('Red T-Shirt');

    // A half-translated entry renders in the wrong language rather than blank —
    // an empty title in a moderation queue is worse than a Turkish one.
    $partial = Product::factory()->create(['title_tr' => 'Sadece Türkçe', 'title_en' => null]);

    expect($partial->localized('title', 'en'))->toBe('Sadece Türkçe')
        ->and($partial->isFullyLocalized('title'))->toBeFalse();
});

it('exposes the per-locale columns for querying', function (): void {
    expect(Product::localizedColumns('title'))->toBe(['title_tr', 'title_en'])
        ->and(Product::localizedAttributes())->toBe(['title', 'description'])
        ->and(Category::localizedColumns('name'))->toBe(['name_tr', 'name_en']);
});

it('scopes a seller to their own proposals, by organization id', function (): void {
    // ADR-030/040 — the tenancy wall. Scoping is by the ID LIST the Core
    // OrganizationAuthorizationContract returns, because a seller may belong to
    // several companies and Catalog may not import Organization to translate
    // uuids. (The test builds real organizations because the FK is real; the
    // MODULE still imports none — that is what LayeringTest proves.)
    [$first, $second, $other] = [proposingOrg(), proposingOrg(), proposingOrg()];

    $mine = Product::factory()->proposedBy($first['id'], $first['uuid'])->create();
    $alsoMine = Product::factory()->proposedBy($second['id'], $second['uuid'])->create();
    $theirs = Product::factory()->proposedBy($other['id'], $other['uuid'])->create();
    $staffAuthored = Product::factory()->create();

    $visible = Product::query()->proposedByAny([$first['id'], $second['id']])->pluck('id');

    expect($visible)->toContain($mine->id, $alsoMine->id)
        ->and($visible)->not->toContain($theirs->id)
        ->and($visible)->not->toContain($staffAuthored->id);
});

it('shows a seller who belongs to nothing an empty catalog, never everyone\'s', function (): void {
    // The failure direction that matters: an unscoped query here would leak the
    // whole catalog's drafts into one seller's panel.
    $org = proposingOrg();
    Product::factory()->count(3)->proposedBy($org['id'], $org['uuid'])->create();

    expect(Product::query()->proposedByAny([])->count())->toBe(0);
});

it('never treats a staff-authored product as any seller\'s', function (): void {
    $org = proposingOrg();
    $staffAuthored = Product::factory()->create();

    expect($staffAuthored->isProposedByAny([$org['id']]))->toBeFalse()
        ->and($staffAuthored->isEditableBy([$org['id']]))->toBeFalse();
});

it('carries no price and no stock column anywhere in the catalog', function (): void {
    // ADR-037, asserted rather than merely documented. This is the module's
    // defining boundary; a migration that quietly added `price` would be a
    // silent re-architecture, so it fails here instead.
    $forbidden = ['price', 'stock', 'quantity', 'cost', 'amount', 'currency_id'];

    $tables = [
        'products' => Product::class,
        'product_variants' => ProductVariant::class,
        'categories' => Category::class,
        'brands' => Brand::class,
        'attributes' => Attribute::class,
        'attribute_values' => AttributeValue::class,
    ];

    foreach ($tables as $table => $model) {
        $columns = Schema::getColumnListing($table);

        foreach ($forbidden as $column) {
            expect($columns)->not->toContain($column, "{$table} must not carry a {$column} column");
        }
    }
});

it('seeds a starter taxonomy that is safe to run twice', function (): void {
    // §13.3 — a starting point, not a fixture. Idempotent so a deploy can run
    // it unconditionally without duplicating a branch.
    (new CatalogTaxonomySeeder)->run();
    (new CatalogTaxonomySeeder)->run();

    expect(Category::query()->where('slug', 'giyim')->count())->toBe(1)
        ->and(Category::query()->roots()->count())->toBe(5)
        ->and(Category::query()->leaves()->count())->toBe(12);

    $colour = Attribute::query()->where('code', 'renk')->firstOrFail();

    expect($colour->type)->toBe(AttributeType::Select)
        ->and($colour->is_variant_defining)->toBeTrue()
        ->and($colour->values()->count())->toBe(6);

    $warranty = Attribute::query()->where('code', 'garanti-suresi')->firstOrFail();

    expect($warranty->type)->toBe(AttributeType::Number)
        ->and($warranty->is_variant_defining)->toBeFalse();
});

it('leaves an operator rename in place when the seeder runs again', function (): void {
    (new CatalogTaxonomySeeder)->run();

    Category::query()->where('slug', 'giyim')->update(['name_tr' => 'Tekstil']);

    (new CatalogTaxonomySeeder)->run();

    expect(Category::query()->where('slug', 'giyim')->value('name_tr'))->toBe('Tekstil');
});
