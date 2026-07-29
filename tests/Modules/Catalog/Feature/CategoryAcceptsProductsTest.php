<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource\Pages\EditCategory;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| ADR-047 — `accepts_products` replaces the leaf rule
|--------------------------------------------------------------------------
|
| The decision the owner needed: a product must be fileable at more than one
| depth. Under ADR-038's leaf rule, adding a single sub-category to *Makyaj*
| silently stopped *Makyaj* holding products — the taxonomy's shape decided, and
| the Category Manager could not overrule it.
|
| What is pinned here is the FLAG's own behaviour: the data migration that
| preserved existing validity, and the guard that stops a Category Manager
| orphaning products by closing a category under them. The attach rule itself is
| pinned where it is enforced (ProductLifecycleTest).
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

it('flagged every existing leaf and no container, so nothing broke on deploy', function (): void {
    /*
     * Re-runs the migration's rule against a tree built the way the old data
     * looked. If this ever disagrees, a deploy would have invalidated products
     * that were legal the day before — the one failure mode a data migration
     * has.
     */
    $root = Category::factory()->create(['name_tr' => 'Kozmetik']);
    $mid = Category::factory()->childOf($root)->create(['name_tr' => 'Makyaj']);
    $leaf = Category::factory()->childOf($mid)->create(['name_tr' => 'Göz Makyajı']);

    // Wipe the flag, then apply exactly what the migration applies.
    DB::table('categories')->update(['accepts_products' => false]);
    DB::table('categories')
        ->whereNotExists(function ($query): void {
            $query->select(DB::raw(1))
                ->from('categories as children')
                ->whereColumn('children.parent_id', 'categories.id');
        })
        ->update(['accepts_products' => true]);

    expect($root->fresh()->accepts_products)->toBeFalse()
        ->and($mid->fresh()->accepts_products)->toBeFalse()
        ->and($leaf->fresh()->accepts_products)->toBeTrue();
});

it('lets a category with children accept products — the whole point', function (): void {
    $mid = Category::factory()->create(['accepts_products' => true]);
    Category::factory()->childOf($mid)->create();

    // Both true at once is precisely what the leaf rule could not express.
    expect($mid->isLeaf())->toBeFalse()
        ->and($mid->acceptsProducts())->toBeTrue();
});

it('offers only flagged categories to the attach scope', function (): void {
    $container = Category::factory()->container()->create();
    $attachable = Category::factory()->childOf($container)->create();

    $ids = Category::query()->acceptsProducts()->pluck('id')->all();

    expect($ids)->toContain($attachable->getKey())
        ->and($ids)->not->toContain($container->getKey());
});

/*
|--------------------------------------------------------------------------
| The Category Manager's control, and its one guard
|--------------------------------------------------------------------------
*/

it('lets the Category Manager open a container for products', function (): void {
    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.category_manager')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $category = Category::factory()->container()->create();

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['accepts_products' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->fresh()->accepts_products)->toBeTrue();
});

it('refuses to close a category that still holds products', function (): void {
    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.category_manager')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $category = Category::factory()->create(['accepts_products' => true]);
    Product::factory()->for($category, 'category')->create();

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['accepts_products' => false])
        ->call('save')
        ->assertHasFormErrors(['accepts_products']);

    // Closing it would leave those products attached somewhere that now refuses
    // attachment — valid on disk, invalid on their next edit, and invisible
    // until a seller hit it.
    expect($category->fresh()->accepts_products)->toBeTrue();
});

it('lets a category with no products be closed again', function (): void {
    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.category_manager')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $category = Category::factory()->create(['accepts_products' => true]);

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['accepts_products' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->fresh()->accepts_products)->toBeFalse();
});
