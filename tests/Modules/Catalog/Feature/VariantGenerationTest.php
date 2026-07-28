<?php

declare(strict_types=1);

use App\Modules\Catalog\Application\Actions\BindCategoryAttributeAction;
use App\Modules\Catalog\Application\Actions\GenerateVariantsAction;
use App\Modules\Catalog\Application\Actions\UpsertVariantAction;
use App\Modules\Catalog\Domain\DTOs\BindCategoryAttributeDTO;
use App\Modules\Catalog\Domain\DTOs\GenerateVariantsDTO;
use App\Modules\Catalog\Domain\DTOs\UpsertVariantDTO;
use App\Modules\Catalog\Domain\Events\ProductVariantCreated;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Catalog — variant generation (ADR-039, §13.4)
|--------------------------------------------------------------------------
|
| Cartesian auto-generate, prunable. The cases worth pinning are the ones that
| bite in production: the multiplicative cap, idempotent re-runs (a seller adding
| a colour must not get new SKUs for the combinations they already print on
| labels), and the axis-less product falling out of the same code path rather
| than needing a branch.
|
*/

/**
 * A leaf category with Renk and Beden as variant axes — the clothing shape the
 * whole variant design exists for.
 *
 * @return array{category: Category, colour: Attribute, size: Attribute}
 */
function clothingCategory(int $colours = 2, int $sizes = 2): array
{
    $root = Category::factory()->create();
    $category = Category::factory()->childOf($root)->create();

    $colour = Attribute::factory()->variantDefining()->withValues($colours)->create(['code' => 'renk']);
    $size = Attribute::factory()->variantDefining()->withValues($sizes)->create(['code' => 'beden']);

    foreach ([$colour, $size] as $attribute) {
        BindCategoryAttributeAction::make()->run($category, new BindCategoryAttributeDTO(
            attributeUuid: $attribute->uuid,
            isVariantDefining: true,
        ));
    }

    return [
        'category' => $category,
        'colour' => $colour->fresh(),
        'size' => $size->fresh(),
    ];
}

it('multiplies the chosen axes out into one variant per combination', function (): void {
    Event::fake([ProductVariantCreated::class]);

    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory(2, 2);
    $product = Product::factory()->for($category, 'category')->create();

    $created = GenerateVariantsAction::make()->run($product, new GenerateVariantsDTO(
        selection: [
            $colour->uuid => $colour->values->pluck('uuid')->all(),
            $size->uuid => $size->values->pluck('uuid')->all(),
        ],
    ));

    expect($created)->toHaveCount(4)
        ->and($product->fresh()->variants()->count())->toBe(4);

    // Each variant carries one value per axis.
    foreach ($product->fresh()->variants as $variant) {
        expect($variant->attributeValues()->count())->toBe(2);
    }

    Event::assertDispatchedTimes(ProductVariantCreated::class, 4);
});

it('generates a single default variant for a product with no axes', function (): void {
    // ADR-039 — never a special case, just the empty combination.
    $root = Category::factory()->create();
    $category = Category::factory()->childOf($root)->create();
    $product = Product::factory()->for($category, 'category')->create();

    $created = GenerateVariantsAction::make()->run($product, new GenerateVariantsDTO);

    expect($created)->toHaveCount(1)
        ->and($created[0]->is_default)->toBeTrue()
        ->and($created[0]->combination_key)->toBe(ProductVariant::NO_AXES_KEY)
        ->and($created[0]->attributeValues()->count())->toBe(0);
});

it('prunes the combinations the seller excluded', function (): void {
    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory(2, 2);
    $product = Product::factory()->for($category, 'category')->create();

    $red = $colour->values->first();
    $small = $size->values->first();

    $created = GenerateVariantsAction::make()->run($product, new GenerateVariantsDTO(
        selection: [
            $colour->uuid => $colour->values->pluck('uuid')->all(),
            $size->uuid => $size->values->pluck('uuid')->all(),
        ],
        exclude: [ProductVariant::combinationKeyFor([$red->id, $small->id])],
    ));

    expect($created)->toHaveCount(3);
});

it('adds only the new combinations when run again', function (): void {
    // A seller adding a colour must not get fresh SKUs for the combinations
    // already printed on labels.
    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory(2, 2);
    $product = Product::factory()->for($category, 'category')->create();

    $firstColour = $colour->values->first();

    GenerateVariantsAction::make()->run($product, new GenerateVariantsDTO(
        selection: [
            $colour->uuid => [$firstColour->uuid],
            $size->uuid => $size->values->pluck('uuid')->all(),
        ],
    ));

    $originalSkus = $product->fresh()->variants->pluck('sku')->sort()->values()->all();

    $created = GenerateVariantsAction::make()->run($product->fresh(), new GenerateVariantsDTO(
        selection: [
            $colour->uuid => $colour->values->pluck('uuid')->all(),
            $size->uuid => $size->values->pluck('uuid')->all(),
        ],
    ));

    expect($created)->toHaveCount(2)
        ->and($product->fresh()->variants()->count())->toBe(4);

    $stillThere = $product->fresh()->variants->pluck('sku')->intersect($originalSkus)->sort()->values()->all();

    expect($stillThere)->toBe($originalSkus);
});

it('refuses a selection that would generate more variants than the cap', function (): void {
    // §13.4 — cartesian growth is multiplicative, and "select all" on four
    // attributes is something a seller does by accident.
    config(['catalog.variants.max_generated' => 5]);

    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory(3, 3);
    $product = Product::factory()->for($category, 'category')->create();

    expect(fn () => GenerateVariantsAction::make()->run($product, new GenerateVariantsDTO(
        selection: [
            $colour->uuid => $colour->values->pluck('uuid')->all(),
            $size->uuid => $size->values->pluck('uuid')->all(),
        ],
    )))->toThrow(CatalogException::class);

    // Nothing written — the refusal is before the loop, not halfway through it.
    expect($product->fresh()->variants()->count())->toBe(0);
});

it('refuses an axis the category does not define', function (): void {
    // Quietly dropping it would generate a smaller variant set than the seller
    // ticked, and they would not find out until a buyer could not pick a size.
    ['category' => $category, 'colour' => $colour] = clothingCategory();
    $stranger = Attribute::factory()->variantDefining()->withValues(2)->create();
    $product = Product::factory()->for($category, 'category')->create();

    expect(fn () => GenerateVariantsAction::make()->run($product, new GenerateVariantsDTO(
        selection: [$stranger->uuid => $stranger->fresh()->values->pluck('uuid')->all()],
    )))->toThrow(CatalogException::class);
});

it('refuses a value that does not belong to its axis', function (): void {
    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory();
    $product = Product::factory()->for($category, 'category')->create();

    expect(fn () => GenerateVariantsAction::make()->run($product, new GenerateVariantsDTO(
        selection: [$colour->uuid => [$size->values->first()->uuid]],
    )))->toThrow(CatalogException::class);
});

it('generates a readable SKU per variant, globally unique', function (): void {
    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory(2, 2);
    $product = Product::factory()->for($category, 'category')->create([
        'title_tr' => 'Pamuklu Tişört',
    ]);

    GenerateVariantsAction::make()->run($product, new GenerateVariantsDTO(
        selection: [
            $colour->uuid => $colour->values->pluck('uuid')->all(),
            $size->uuid => $size->values->pluck('uuid')->all(),
        ],
    ));

    $skus = $product->fresh()->variants->pluck('sku');

    expect($skus->unique())->toHaveCount(4);

    foreach ($skus as $sku) {
        expect($sku)->toStartWith('PAMUKL-')
            // The alphabet drops the characters people mis-transcribe.
            ->and($sku)->not->toMatch('/[01OI]/');
    }
});

it('adds one variant by hand', function (): void {
    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory();
    $product = Product::factory()->for($category, 'category')->create();

    $variant = UpsertVariantAction::make()->run($product, new UpsertVariantDTO(
        valueUuids: [$colour->values->first()->uuid, $size->values->first()->uuid],
        sku: 'TSHIRT-RED-M',
    ));

    expect($variant->sku)->toBe('TSHIRT-RED-M')
        ->and($variant->attributeValues()->count())->toBe(2)
        ->and($product->fresh()->variants()->count())->toBe(1);
});

it('refuses a second variant with the same combination', function (): void {
    // §3.3 — the readable refusal that gets there before the UNIQUE index.
    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory();
    $product = Product::factory()->for($category, 'category')->create();

    $values = [$colour->values->first()->uuid, $size->values->first()->uuid];

    UpsertVariantAction::make()->run($product, new UpsertVariantDTO(valueUuids: $values));

    expect(fn () => UpsertVariantAction::make()->run(
        $product->fresh(),
        new UpsertVariantDTO(valueUuids: $values),
    ))->toThrow(CatalogException::class);
});

it('treats the same values in a different order as the same variant', function (): void {
    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory();
    $product = Product::factory()->for($category, 'category')->create();

    $red = $colour->values->first()->uuid;
    $medium = $size->values->first()->uuid;

    UpsertVariantAction::make()->run($product, new UpsertVariantDTO(valueUuids: [$red, $medium]));

    expect(fn () => UpsertVariantAction::make()->run(
        $product->fresh(),
        new UpsertVariantDTO(valueUuids: [$medium, $red]),
    ))->toThrow(CatalogException::class);
});

it('keeps an existing SKU through an edit', function (): void {
    // A SKU already in the world is not ours to change.
    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory();
    $product = Product::factory()->for($category, 'category')->create();

    $variant = UpsertVariantAction::make()->run($product, new UpsertVariantDTO(
        valueUuids: [$colour->values->first()->uuid, $size->values->first()->uuid],
        sku: 'TSHIRT-RED-M',
    ));

    UpsertVariantAction::make()->run($product->fresh(), new UpsertVariantDTO(
        valueUuids: [$colour->values->first()->uuid, $size->values->first()->uuid],
        variantUuid: $variant->uuid,
        barcode: '8691234567890',
    ));

    expect($variant->fresh()->sku)->toBe('TSHIRT-RED-M')
        ->and($variant->fresh()->barcode)->toBe('8691234567890');
});

it('never prunes a product down to zero variants', function (): void {
    // §3.3 — a product with none is not sellable, and Offers reference variants.
    // Reached by excluding every combination the selection would produce, so
    // nothing is created and everything existing is doomed.
    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory();
    $product = Product::factory()->for($category, 'category')->create();

    $selection = [
        $colour->uuid => $colour->values->pluck('uuid')->all(),
        $size->uuid => $size->values->pluck('uuid')->all(),
    ];

    GenerateVariantsAction::make()->run($product, new GenerateVariantsDTO(selection: $selection));

    $everyCombination = $product->fresh()->variants->pluck('combination_key')->all();

    expect(fn () => GenerateVariantsAction::make()->run($product->fresh(), new GenerateVariantsDTO(
        selection: $selection,
        exclude: $everyCombination,
        pruneMissing: true,
    )))->toThrow(CatalogException::class);

    expect($product->fresh()->variants()->count())->toBe(4);
});

it('collapses a product to its default variant when every axis is deselected', function (): void {
    // Deselecting all axes says "this product has no variants any more". The
    // empty cartesian is one empty combination, so the default variant is
    // created and the axis variants are pruned — the product is left valid,
    // never variant-less. Pinned because it is a destructive-looking result
    // that is in fact the coherent one.
    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory();
    $product = Product::factory()->for($category, 'category')->create();

    GenerateVariantsAction::make()->run($product, new GenerateVariantsDTO(
        selection: [
            $colour->uuid => $colour->values->pluck('uuid')->all(),
            $size->uuid => $size->values->pluck('uuid')->all(),
        ],
    ));

    GenerateVariantsAction::make()->run($product->fresh(), new GenerateVariantsDTO(
        selection: [],
        pruneMissing: true,
    ));

    $remaining = $product->fresh()->variants;

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->is_default)->toBeTrue()
        // The old SKUs are soft-deleted, not destroyed.
        ->and(ProductVariant::withTrashed()->where('product_id', $product->getKey())->count())->toBe(5);
});

it('soft-deletes pruned variants rather than destroying them', function (): void {
    // A SKU that has been on a label or a warehouse shelf must stay resolvable.
    ['category' => $category, 'colour' => $colour, 'size' => $size] = clothingCategory(2, 2);
    $product = Product::factory()->for($category, 'category')->create();

    GenerateVariantsAction::make()->run($product, new GenerateVariantsDTO(
        selection: [
            $colour->uuid => $colour->values->pluck('uuid')->all(),
            $size->uuid => $size->values->pluck('uuid')->all(),
        ],
    ));

    GenerateVariantsAction::make()->run($product->fresh(), new GenerateVariantsDTO(
        selection: [
            $colour->uuid => [$colour->values->first()->uuid],
            $size->uuid => [$size->values->first()->uuid],
        ],
        pruneMissing: true,
    ));

    expect($product->fresh()->variants()->count())->toBe(1)
        ->and(ProductVariant::withTrashed()->where('product_id', $product->getKey())->count())->toBe(4);
});
