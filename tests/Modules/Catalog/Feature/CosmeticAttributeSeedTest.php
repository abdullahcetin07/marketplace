<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Database\Modules\Catalog\Seeders\CosmeticAttributeDemoSeeder;

/*
|--------------------------------------------------------------------------
| The cosmetic demo schema (Catalog §2.3/§2.4, ADR-038)
|--------------------------------------------------------------------------
|
| A demo seeder that nothing runs is a demo seeder that rots, and this one writes
| to a LIVE catalogue through the real actions — so what it must never do matters
| more than what it does:
|
|   NEVER A VARIANT AXIS   Cilt Tipi and Kullanım are `select` and therefore
|                          eligible to be axes (ADR-039). A binding that said yes
|                          would multiply out "Kuru × Yüz" SKUs of a face cream.
|   NEVER DESTRUCTIVE      `SetProductAttributesAction` REPLACES a product's whole
|                          set. A product a human has curated must survive a
|                          re-run untouched.
|   ALWAYS RE-RUNNABLE     it is run by hand, on a database that already has data.
|
| And the rule the owner asked to have confirmed: the attribute is defined on the
| CATEGORY first, and only then can a product carry a value — asserted here rather
| than only described in prose.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * The cosmetic branch the seeder looks for, plus the demo product in it.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{category: Category, product: Product}
 */
function cosmeticBranch(): array
{
    $root = Category::factory()->create(['name_tr' => 'Kozmetik & Kişisel Bakım', 'slug' => 'kozmetik']);
    $category = Category::factory()->childOf($root)->create([
        'name_tr' => 'Cilt Bakım',
        'slug' => 'cilt-bakim',
    ]);

    $product = Product::factory()->for($category, 'category')->published()->create([
        'title_tr' => 'Bioderma Sensibio AR+ CC Cream',
        'title_en' => 'Bioderma Sensibio AR+ CC Cream',
        // The seeder finds its demo products by GTIN — the catalogue's own dedup
        // key, and the only identifier here that describes the physical product
        // rather than this database.
        'gtin' => '3701129813447',
    ]);

    ProductVariant::factory()->for($product)->create();

    return ['category' => $category, 'product' => $product];
}

it('defines the schema on the category, which is what lets a product carry a value', function (): void {
    $fixture = cosmeticBranch();

    // BEFORE: the field does not exist for this category, so no seller could
    // enter it and the product page's spec table is empty. That was the whole
    // "authoring gap" — it was a taxonomy gap.
    expect($fixture['category']->schemaAttributes()->count())->toBe(0)
        ->and($fixture['product']->descriptiveAttributes()->count())->toBe(0);

    $this->seed(CosmeticAttributeDemoSeeder::class);

    $schema = $fixture['category']->fresh()->schemaAttributes()->pluck('code')->all();

    expect($schema)->toContain('cilt-tipi', 'hacim', 'kullanim', 'mensei');

    $values = $fixture['product']->fresh()->descriptiveAttributes;

    // AFTER: four values on the product, set through the real action — so this is
    // data a seller could have produced, not data only a seeder can.
    expect($values)->toHaveCount(4);
});

it('never turns a cosmetic attribute into a variant axis', function (): void {
    $fixture = cosmeticBranch();

    $this->seed(CosmeticAttributeDemoSeeder::class);

    $bindings = $fixture['category']->fresh()->schemaAttributes;

    foreach ($bindings as $attribute) {
        expect($attribute->getRelationValue('pivot')->is_variant_defining)->toBeFalsy();
    }

    /*
     * `select` attributes are ELIGIBLE to be axes, which is exactly why this is
     * asserted rather than assumed: Cilt Tipi would pass
     * `canDefineVariants()`, and a binding that said yes would make a
     * "Hassas × Yüz" SKU of a single tube of cream.
     */
    expect(Attribute::query()->where('code', 'cilt-tipi')->value('is_variant_defining'))->toBeFalsy()
        // No variant was created either — the product still has the one it started with.
        ->and($fixture['product']->fresh()->variants()->count())->toBe(1);
});

it('leaves nothing to change on a second run', function (): void {
    $fixture = cosmeticBranch();

    $this->seed(CosmeticAttributeDemoSeeder::class);

    $attributeCount = Attribute::query()->count();
    $before = $fixture['product']->fresh()->descriptiveAttributes
        ->map(static fn (Attribute $a): string => $a->code.'='.($a->getRelationValue('pivot')->value ?? $a->getRelationValue('pivot')->attribute_value_id))
        ->sort()->values()->all();

    $this->seed(CosmeticAttributeDemoSeeder::class);

    $after = $fixture['product']->fresh()->descriptiveAttributes
        ->map(static fn (Attribute $a): string => $a->code.'='.($a->getRelationValue('pivot')->value ?? $a->getRelationValue('pivot')->attribute_value_id))
        ->sort()->values()->all();

    // It is run by hand against a live catalogue, so a second run duplicating
    // attributes or values is the failure that matters most.
    expect(Attribute::query()->count())->toBe($attributeCount)
        ->and($after)->toBe($before)
        ->and($fixture['category']->fresh()->schemaAttributes()->count())->toBe(4);
});

it('refuses to overwrite values somebody curated afterwards', function (): void {
    $fixture = cosmeticBranch();

    $this->seed(CosmeticAttributeDemoSeeder::class);

    // A human corrects the volume after the demo seed — the exact scenario the
    // owner is heading into, since they curate the real schema next.
    $hacim = Attribute::query()->where('code', 'hacim')->firstOrFail();

    $fixture['product']->descriptiveAttributes()->updateExistingPivot($hacim->getKey(), ['value' => '75 ml']);

    $this->seed(CosmeticAttributeDemoSeeder::class);

    $pivot = $fixture['product']->fresh()->descriptiveAttributes
        ->firstWhere('code', 'hacim')?->getRelationValue('pivot');

    /*
     * `SetProductAttributesAction` is a full REPLACEMENT — right for a form
     * submit, destructive for a seeder run months later. So a product that
     * already carries values is skipped entirely rather than re-synced.
     */
    expect($pivot?->value)->toBe('75 ml');
});

it('skips a branch this deployment does not have, rather than failing', function (): void {
    // No cosmetic categories at all: a platform that never opened that branch.
    $this->seed(CosmeticAttributeDemoSeeder::class);

    // The attributes still exist — they are platform-owned and a Category Manager
    // can bind them wherever they like. Only the bindings had nowhere to go.
    expect(Attribute::query()->where('code', 'cilt-tipi')->exists())->toBeTrue();
});

it('gives a couple of unrelated demo products only the category-agnostic pair', function (): void {
    cosmeticBranch();

    // A seeded demo product in a category that has nothing to do with cosmetics.
    $filler = Category::factory()->childOf(Category::factory()->create())->create(['name_tr' => 'Minima Fuga']);
    $product = Product::factory()->for($filler, 'category')->published()->create(['gtin' => null]);

    $this->seed(CosmeticAttributeDemoSeeder::class);

    $codes = $product->fresh()->descriptiveAttributes->pluck('code')->sort()->values()->all();

    /*
     * A volume and a country of origin are true of nearly anything sold in a box.
     * A SKIN TYPE IS NOT — putting "Cilt Tipi: Kuru" on a product with no skin
     * would make the demo dishonest rather than thin, so the cosmetic half is
     * never bound outside the cosmetic branch.
     */
    expect($codes)->toBe(['hacim', 'mensei'])
        ->and($filler->fresh()->schemaAttributes()->pluck('code')->sort()->values()->all())
        ->toBe(['hacim', 'mensei']);
});
