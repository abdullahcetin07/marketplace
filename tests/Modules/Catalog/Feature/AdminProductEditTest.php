<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource;
use App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource\Pages\EditProductModeration;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The admin's product correction screen (2026-09-08)
|--------------------------------------------------------------------------
|
| It exists because the bulk import stopped editing what it finds. That was the
| right call — a sheet with no `aciklama` column blanked 1,941 descriptions — but
| it leaves exactly one way to fix a typo in an imported title, and this is it.
| So the three things worth pinning are: a moderator can reach it, the write goes
| through the authoring action (or search and both feeds go stale), and it does
| not move the slug Google has already indexed.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function editingAdmin(string $role = 'super_admin'): Admin
{
    /** @var Admin $admin */
    $admin = Admin::factory()->create();
    $admin->syncRoles([config("marketplace.roles.{$role}")]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    return $admin;
}

function editableProduct(): Product
{
    return Product::factory()
        ->for(Category::factory()->childOf(Category::factory()->create())->create(), 'category')
        ->published()
        ->create(['title_tr' => 'Yanlış Yazılmış Ürün 7|5 ml', 'description_tr' => '']);
}

it('lets a moderator correct an imported product, through the authoring action', function (): void {
    /*
     * The ADR-074/076/088 rule. Filament's own `handleRecordUpdate()` would
     * `fill()->save()` and fire nothing the domain listens to — the row would be
     * right in the table and stale in search, the storefront and both feeds.
     */
    $this->actingAs(editingAdmin(), 'admin');

    $product = editableProduct();

    Event::fake(['eloquent.saved: '.Product::class]);

    Livewire::test(EditProductModeration::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'title_tr' => 'Doğru Yazılmış Ürün 7,5 ml',
            'description_tr' => 'Elle yazılmış açıklama.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->title_tr)->toBe('Doğru Yazılmış Ürün 7,5 ml')
        ->and($product->fresh()->description_tr)->toBe('Elle yazılmış açıklama.');

    Event::assertDispatched('eloquent.saved: '.Product::class);
});

it('does not move the slug when the title is corrected', function (): void {
    $this->actingAs(editingAdmin(), 'admin');

    $product = editableProduct();
    $slug = $product->slug;

    Livewire::test(EditProductModeration::class, ['record' => $product->getRouteKey()])
        ->fillForm(['title_tr' => 'Bambaşka Bir Ürün Adı'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->slug)->toBe($slug);
});

it('keeps the brand it was not asked to change', function (): void {
    // PATCH semantics: a field the person did not touch comes back as itself, not
    // as null — the failure mode that would quietly strip every brand.
    $this->actingAs(editingAdmin(), 'admin');

    $brand = Brand::factory()->create();
    $product = editableProduct();
    $product->brand()->associate($brand)->save();

    Livewire::test(EditProductModeration::class, ['record' => $product->getRouteKey()])
        ->fillForm(['title_tr' => 'Yalnızca Başlık Düzeltildi'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->brand_id)->toBe($brand->getKey());
});

it('keeps the correction screen away from an admin who may not moderate', function (): void {
    $this->actingAs(editingAdmin('support'), 'admin');

    expect(ProductModerationResource::canEdit(editableProduct()))->toBeFalse();
});
