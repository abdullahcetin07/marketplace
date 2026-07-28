<?php

declare(strict_types=1);

use App\Modules\Catalog\Application\Actions\ArchiveCategoryAction;
use App\Modules\Catalog\Application\Actions\BindCategoryAttributeAction;
use App\Modules\Catalog\Application\Actions\CreateAttributeAction;
use App\Modules\Catalog\Application\Actions\CreateAttributeValueAction;
use App\Modules\Catalog\Application\Actions\CreateBrandAction;
use App\Modules\Catalog\Application\Actions\CreateCategoryAction;
use App\Modules\Catalog\Application\Actions\ReorderCategoriesAction;
use App\Modules\Catalog\Application\Actions\UpdateCategoryAction;
use App\Modules\Catalog\Domain\DTOs\BindCategoryAttributeDTO;
use App\Modules\Catalog\Domain\DTOs\CreateAttributeDTO;
use App\Modules\Catalog\Domain\DTOs\CreateAttributeValueDTO;
use App\Modules\Catalog\Domain\DTOs\CreateBrandDTO;
use App\Modules\Catalog\Domain\DTOs\CreateCategoryDTO;
use App\Modules\Catalog\Domain\DTOs\UpdateCategoryDTO;
use App\Modules\Catalog\Domain\Enums\AttributeType;
use App\Modules\Catalog\Domain\Events\AttributeCreated;
use App\Modules\Catalog\Domain\Events\BrandCreated;
use App\Modules\Catalog\Domain\Events\CategoryArchived;
use App\Modules\Catalog\Domain\Events\CategoryCreated;
use App\Modules\Catalog\Domain\Events\CategoryUpdated;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Category;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Catalog — taxonomy management (ADR-038, §4)
|--------------------------------------------------------------------------
|
| The Category Manager's surface. The interesting cases are not the happy paths
| but the two that corrupt data if they get through: a category moved inside
| itself, and a variant axis on a type that has no finite values.
|
*/

it('creates a root category with a path that locates it', function (): void {
    Event::fake([CategoryCreated::class]);

    $category = CreateCategoryAction::make()->run(new CreateCategoryDTO(
        name: ['tr' => 'Giyim', 'en' => 'Clothing'],
    ));

    expect($category->path)->toBe('/'.$category->id.'/')
        ->and($category->depth)->toBe(0)
        // Str::slug transliterates, which is what a URL needs.
        ->and($category->slug)->toBe('giyim')
        ->and($category->localized('name', 'en'))->toBe('Clothing');

    Event::assertDispatched(CategoryCreated::class);
});

it('transliterates a Turkish name into an ASCII slug', function (): void {
    $category = CreateCategoryAction::make()->run(new CreateCategoryDTO(
        name: ['tr' => 'Kadın Giyim & Ayakkabı'],
    ));

    expect($category->slug)->toBe('kadin-giyim-ayakkabi');
});

it('suffixes a slug that is already taken rather than failing', function (): void {
    CreateCategoryAction::make()->run(new CreateCategoryDTO(name: ['tr' => 'Giyim']));
    $second = CreateCategoryAction::make()->run(new CreateCategoryDTO(name: ['tr' => 'Giyim']));

    expect($second->slug)->toBe('giyim-2');
});

it('nests a child beneath its parent', function (): void {
    $parent = CreateCategoryAction::make()->run(new CreateCategoryDTO(name: ['tr' => 'Giyim']));

    $child = CreateCategoryAction::make()->run(new CreateCategoryDTO(
        name: ['tr' => 'Tişört'],
        parentUuid: $parent->uuid,
    ));

    expect($child->path)->toBe($parent->path.$child->id.'/')
        ->and($child->depth)->toBe(1)
        ->and($parent->isLeaf())->toBeFalse();
});

it('rewrites the whole subtree when a category moves', function (): void {
    // The expensive edit the materialised path costs (§13.1).
    $oldHome = Category::factory()->create();
    $newHome = Category::factory()->create();
    $moving = Category::factory()->childOf($oldHome)->create();
    $child = Category::factory()->childOf($moving)->create();
    $grandchild = Category::factory()->childOf($child)->create();

    UpdateCategoryAction::make()->run($moving, new UpdateCategoryDTO(
        parentUuid: $newHome->uuid,
        present: ['parentUuid'],
    ));

    expect($moving->fresh()->path)->toBe($newHome->path.$moving->id.'/')
        ->and($child->fresh()->path)->toBe($newHome->path.$moving->id.'/'.$child->id.'/')
        ->and($grandchild->fresh()->path)->toBe(
            $newHome->path.$moving->id.'/'.$child->id.'/'.$grandchild->id.'/'
        )
        ->and($grandchild->fresh()->depth)->toBe(3);
});

it('refuses to move a category inside its own descendant', function (): void {
    // Without this the path becomes self-referential and the branch vanishes
    // from every prefix scan — data loss wearing the costume of a successful
    // save.
    $parent = Category::factory()->create();
    $child = Category::factory()->childOf($parent)->create();

    expect(fn () => UpdateCategoryAction::make()->run($parent, new UpdateCategoryDTO(
        parentUuid: $child->uuid,
        present: ['parentUuid'],
    )))->toThrow(CatalogException::class);

    expect($parent->fresh()->parent_id)->toBeNull();
});

it('refuses to move a category inside itself', function (): void {
    $category = Category::factory()->create();

    expect(fn () => UpdateCategoryAction::make()->run($category, new UpdateCategoryDTO(
        parentUuid: $category->uuid,
        present: ['parentUuid'],
    )))->toThrow(CatalogException::class);
});

it('leaves a field alone when the patch does not mention it', function (): void {
    // The failure this guards: an edit form that omits `slug` blanking a live
    // public URL.
    $category = Category::factory()->create(['slug' => 'giyim', 'is_active' => true]);

    UpdateCategoryAction::make()->run($category, new UpdateCategoryDTO(
        name: ['tr' => 'Tekstil'],
    ));

    expect($category->fresh()->slug)->toBe('giyim')
        ->and($category->fresh()->is_active)->toBeTrue()
        ->and($category->fresh()->localized('name', 'tr'))->toBe('Tekstil');
});

it('renumbers siblings from zero when reordered', function (): void {
    Event::fake([CategoryUpdated::class]);

    $a = Category::factory()->create(['position' => 7]);
    $b = Category::factory()->create(['position' => 3]);
    $c = Category::factory()->create(['position' => 9]);

    ReorderCategoriesAction::make()->run([$c->uuid, $a->uuid, $b->uuid]);

    // Re-numbered 0..n-1 every time, so repeated reordering never accumulates
    // gaps or ties.
    expect($c->fresh()->position)->toBe(0)
        ->and($a->fresh()->position)->toBe(1)
        ->and($b->fresh()->position)->toBe(2);

    Event::assertDispatchedTimes(CategoryUpdated::class, 3);
});

it('archives a category by deactivating it, never deleting it', function (): void {
    Event::fake([CategoryArchived::class]);

    $category = Category::factory()->create();

    ArchiveCategoryAction::make()->run($category);

    expect($category->fresh()->is_active)->toBeFalse()
        // Still there: products point at it (ADR-015/§3.5).
        ->and(Category::query()->whereKey($category->getKey())->exists())->toBeTrue();

    Event::assertDispatched(CategoryArchived::class);
});

it('refuses to archive a branch that still has active children', function (): void {
    $parent = Category::factory()->create();
    Category::factory()->childOf($parent)->create();

    expect(fn () => ArchiveCategoryAction::make()->run($parent))
        ->toThrow(CatalogException::class);

    expect($parent->fresh()->is_active)->toBeTrue();
});

it('archives a branch once its children are archived', function (): void {
    $parent = Category::factory()->create();
    $child = Category::factory()->childOf($parent)->inactive()->create();

    ArchiveCategoryAction::make()->run($parent);

    expect($parent->fresh()->is_active)->toBeFalse()
        ->and($child->fresh()->is_active)->toBeFalse();
});

it('creates an attribute with its enumerated values', function (): void {
    Event::fake([AttributeCreated::class]);

    $attribute = CreateAttributeAction::make()->run(new CreateAttributeDTO(
        code: 'renk',
        name: ['tr' => 'Renk', 'en' => 'Colour'],
        type: AttributeType::Select,
        isVariantDefining: true,
    ));

    CreateAttributeValueAction::make()->run(new CreateAttributeValueDTO(
        attributeUuid: $attribute->uuid,
        value: 'kirmizi',
        label: ['tr' => 'Kırmızı', 'en' => 'Red'],
    ));

    expect($attribute->fresh()->values()->count())->toBe(1)
        ->and($attribute->fresh()->values()->first()->localized('label', 'tr'))->toBe('Kırmızı');

    Event::assertDispatched(AttributeCreated::class);
});

it('refuses a variant-defining attribute that has no finite values', function (): void {
    // ADR-039 — a cartesian needs enumerable axes. "Ağırlık: 2.4 kg" is a fact,
    // not an axis.
    expect(fn () => CreateAttributeAction::make()->run(new CreateAttributeDTO(
        code: 'agirlik',
        name: ['tr' => 'Ağırlık'],
        type: AttributeType::Number,
        isVariantDefining: true,
    )))->toThrow(CatalogException::class);
});

it('refuses predefined values on a non-select attribute', function (): void {
    $attribute = Attribute::factory()->ofType(AttributeType::Text)->create();

    expect(fn () => CreateAttributeValueAction::make()->run(new CreateAttributeValueDTO(
        attributeUuid: $attribute->uuid,
        value: 'anything',
        label: ['tr' => 'Herhangi'],
    )))->toThrow(CatalogException::class);
});

it('binds one attribute to two categories with opposite flags', function (): void {
    // §2.3, the whole reason the flags live on the binding: Renk is an axis in
    // Giyim and a description in Mobilya, and both filter on the same colours.
    $clothing = Category::factory()->create();
    $furniture = Category::factory()->create();
    $colour = Attribute::factory()->variantDefining()->withValues(3)->create();

    BindCategoryAttributeAction::make()->run($clothing, new BindCategoryAttributeDTO(
        attributeUuid: $colour->uuid,
        isRequired: true,
        isVariantDefining: true,
    ));

    BindCategoryAttributeAction::make()->run($furniture, new BindCategoryAttributeDTO(
        attributeUuid: $colour->uuid,
        isRequired: false,
        isVariantDefining: false,
    ));

    expect($clothing->schemaAttributes()->wherePivot('is_variant_defining', true)->count())->toBe(1)
        ->and($furniture->schemaAttributes()->wherePivot('is_variant_defining', true)->count())->toBe(0);
});

it('re-configures an existing binding rather than failing on it', function (): void {
    // "Set the schema for this category" is the operation a Category Manager
    // performs; a second call is an edit, not a duplicate.
    $category = Category::factory()->create();
    $attribute = Attribute::factory()->variantDefining()->withValues(2)->create();

    BindCategoryAttributeAction::make()->run($category, new BindCategoryAttributeDTO(
        attributeUuid: $attribute->uuid,
        isRequired: false,
    ));

    BindCategoryAttributeAction::make()->run($category, new BindCategoryAttributeDTO(
        attributeUuid: $attribute->uuid,
        isRequired: true,
    ));

    expect($category->schemaAttributes()->count())->toBe(1)
        ->and($category->schemaAttributes()->wherePivot('is_required', true)->count())->toBe(1);
});

it('refuses to make a non-enumerable attribute a variant axis in a category', function (): void {
    $category = Category::factory()->create();
    $weight = Attribute::factory()->ofType(AttributeType::Number)->create();

    expect(fn () => BindCategoryAttributeAction::make()->run($category, new BindCategoryAttributeDTO(
        attributeUuid: $weight->uuid,
        isVariantDefining: true,
    )))->toThrow(CatalogException::class);
});

it('creates a brand with a unique slug', function (): void {
    Event::fake([BrandCreated::class]);

    $first = CreateBrandAction::make()->run(new CreateBrandDTO(name: 'Beko'));
    $second = CreateBrandAction::make()->run(new CreateBrandDTO(name: 'Beko'));

    expect($first->slug)->toBe('beko')
        ->and($second->slug)->toBe('beko-2');

    Event::assertDispatchedTimes(BrandCreated::class, 2);
});
