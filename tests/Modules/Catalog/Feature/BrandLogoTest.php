<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Presentation\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Modules\Catalog\Presentation\Filament\Resources\BrandResource\Pages\EditBrand;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Brand logo upload (§2.2, §6)
|--------------------------------------------------------------------------
|
| The list has always RENDERED a logo and nothing could ever set one — the field
| simply did not exist on the form. So the assertions here are mostly about the
| media write actually landing, plus the two behaviours that are easy to get
| backwards on a single-file collection:
|
|  - a second upload REPLACES the first, because `logoUrl()` reads the first
|    image and an appended file would upload fine and change nothing;
|  - an edit with no upload leaves the existing logo alone, because reading
|    "absent" as "clear it" would delete a logo on every typo fix.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Storage::fake(config('marketplace.media.public_disk'));
    Storage::fake(config('filament.default_filesystem_disk'));

    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.category_manager')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');
});

it('creates a brand with a logo, persisting the media', function (): void {
    Livewire::test(CreateBrand::class)
        ->fillForm([
            'name' => 'Beko',
            'logo' => UploadedFile::fake()->image('beko.png', 200, 200),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $brand = Brand::query()->where('name', 'Beko')->sole();

    expect($brand->getMedia('images'))->toHaveCount(1)
        // The collection points at the PUBLIC disk (HasMedia) — catalog imagery
        // is meant to be seen, and the brand mark most of all.
        ->and($brand->getFirstMedia('images')->disk)->toBe(config('marketplace.media.public_disk'))
        ->and($brand->logoUrl())->not->toBeNull();
});

it('still creates a brand with no logo at all', function (): void {
    Livewire::test(CreateBrand::class)
        ->fillForm(['name' => 'Arçelik'])
        ->call('create')
        ->assertHasNoFormErrors();

    // Optional, and it must stay optional: a brand is usable the moment it has
    // a name.
    expect(Brand::query()->where('name', 'Arçelik')->sole()->logoUrl())->toBeNull();
});

it('replaces the logo rather than appending a second one', function (): void {
    $brand = Brand::factory()->create(['name' => 'Beko']);

    Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
        ->fillForm(['logo' => UploadedFile::fake()->image('first.png')])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
        ->fillForm(['logo' => UploadedFile::fake()->image('second.png')])
        ->call('save')
        ->assertHasNoFormErrors();

    // Appending would upload successfully and leave the OLD logo live, since
    // logoUrl() reads the first image — a bug that looks like nothing happened.
    expect($brand->fresh()->getMedia('images'))->toHaveCount(1)
        ->and($brand->fresh()->getFirstMedia('images')->file_name)->toContain('second');
});

it('leaves an existing logo alone when the edit carries no upload', function (): void {
    $brand = Brand::factory()->create(['name' => 'Beko']);

    Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
        ->fillForm(['logo' => UploadedFile::fake()->image('logo.png')])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
        ->fillForm(['name' => 'Beko Elektronik'])
        ->call('save')
        ->assertHasNoFormErrors();

    // Renaming a brand must not cost it its mark.
    expect($brand->fresh()->name)->toBe('Beko Elektronik')
        ->and($brand->fresh()->getMedia('images'))->toHaveCount(1);
});
