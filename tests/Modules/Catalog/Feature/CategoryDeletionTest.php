<?php

declare(strict_types=1);

use App\Modules\Catalog\Application\Actions\DeleteCategoryAction;
use App\Modules\Catalog\Domain\Events\CategoryArchived;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource\Pages\ListCategories;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Deleting a category that never held anything (§3.2)
|--------------------------------------------------------------------------
|
| Archiving is the taxonomy's normal removal: products point at categories and
| slugs are public URL segments, so withdrawing one must not orphan or 404
| anything. Deletion exists for the other case — a node typed wrong five minutes
| ago, which archiving would leave as permanent litter in a tree whose value is
| that it reads cleanly.
|
| So the two guards ARE the feature. Without them this is just a delete button
| on the catalog's taxonomy, which is the thing ADR-015 and the RESTRICT foreign
| key were both put in place to prevent.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

it('deletes a category with no products and no children', function (): void {
    Event::fake([CategoryArchived::class]);
    $category = Category::factory()->create();

    DeleteCategoryAction::make()->run($category);

    expect(Category::query()->whereKey($category->getKey())->exists())->toBeFalse();

    // Consumers ask "is this gone from the taxonomy" — the same question an
    // archive answers, so it is the same event rather than a second one every
    // listener would have to branch on.
    Event::assertDispatched(CategoryArchived::class);
});

it('refuses to delete a category that holds products', function (): void {
    $category = Category::factory()->create();
    Product::factory()->for($category, 'category')->create();

    expect(fn () => DeleteCategoryAction::make()->run($category))
        ->toThrow(CatalogException::class);

    // Deleting under a product is exactly what archiving exists to avoid.
    expect(Category::query()->whereKey($category->getKey())->exists())->toBeTrue();
});

it('refuses to delete a category that has children', function (): void {
    $parent = Category::factory()->create();
    Category::factory()->childOf($parent)->create();

    expect(fn () => DeleteCategoryAction::make()->run($parent))
        ->toThrow(CatalogException::class);

    expect(Category::query()->whereKey($parent->getKey())->exists())->toBeTrue();
});

it('refuses even when the only child is inactive', function (): void {
    $parent = Category::factory()->create();
    Category::factory()->childOf($parent)->inactive()->create();

    /*
     * An inactive child reads as "empty" in the tree, which is precisely why
     * this case is stated: archiving only cares about ACTIVE children, and
     * borrowing that rule here would strand the inactive one under a parent
     * that no longer exists.
     */
    expect(fn () => DeleteCategoryAction::make()->run($parent))
        ->toThrow(CatalogException::class);
});

it('frees the slug, so a mis-typed category can be re-created', function (): void {
    $category = Category::factory()->create(['slug' => 'kozmetik']);

    DeleteCategoryAction::make()->run($category);

    // A hard delete rather than a soft one: a soft-deleted node would keep its
    // globally-unique slug reserved forever, for nothing to restore.
    $recreated = Category::factory()->create(['slug' => 'kozmetik']);

    expect($recreated->exists)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The Category Manager's surface
|--------------------------------------------------------------------------
*/

it('offers delete only on a category that can actually be deleted', function (): void {
    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.category_manager')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $empty = Category::factory()->create();
    $withProduct = Category::factory()->create();
    Product::factory()->for($withProduct, 'category')->create();
    $withChild = Category::factory()->create();
    Category::factory()->childOf($withChild)->create();

    $page = Livewire::test(ListCategories::class);

    // A refusal the Category Manager could have been spared reads as the tool
    // being obstructive rather than careful.
    $page->assertTableActionVisible('delete', $empty)
        ->assertTableActionHidden('delete', $withProduct)
        ->assertTableActionHidden('delete', $withChild);
});

it('deletes from the tree through the action', function (): void {
    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.category_manager')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $category = Category::factory()->create();

    Livewire::test(ListCategories::class)->callTableAction('delete', $category);

    expect(Category::query()->whereKey($category->getKey())->exists())->toBeFalse();
});
