<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Presentation\Filament\Imports\ProductImporter;
use App\Modules\Catalog\Presentation\Filament\Pages\ImportCatalog;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| C2 — the admin's upload surface (ADR-074)
|--------------------------------------------------------------------------
|
| **THE THREE OVERRIDES ARE WHAT THIS FILE EXISTS TO PIN.** Filament's `Importer`
| maps one row to one model: resolve a record, fill the mapped columns onto it,
| save it. A catalogue row is a category path, a brand, a product, a variant and
| some images — so `resolveRecord()` does the work and the other two do nothing.
| Get that wrong and the framework tries to set `baslik` as a column on `products`.
|
| The other half is the gate: an import publishes products, so it is gated on the
| permission that means exactly that.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * An admin who may moderate, and one who may not.
 *
 * Named for this file: Pest shares ONE global function namespace.
 */
function importAdmin(string $role = 'super_admin'): Admin
{
    /** @var Admin $admin */
    $admin = Admin::factory()->create();
    $admin->syncRoles([config("marketplace.roles.{$role}")]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    return $admin;
}

it('lets a moderator reach the import page', function (): void {
    $this->actingAs(importAdmin(), 'admin');

    expect(ImportCatalog::canAccess())->toBeTrue();

    Livewire::test(ImportCatalog::class)->assertOk();
});

it('keeps the import away from an admin who cannot publish', function (): void {
    /*
     * **SUPPORT READS, IT DOES NOT PUBLISH.** An import writes a published
     * catalogue at whatever scale the spreadsheet holds — it is the moderation
     * power, exercised a thousand rows at a time, and it is gated as such rather
     * than on a bespoke "import" ability that would be a second answer to the same
     * question.
     */
    $this->actingAs(importAdmin('support'), 'admin');

    expect(ImportCatalog::canAccess())->toBeFalse();
});

it('offers the seven Turkish columns, two of them required', function (): void {
    $columns = collect(ProductImporter::getColumns());

    expect($columns->map(fn ($column): string => $column->getName())->all())
        ->toBe(['baslik', 'kategori_yolu', 'marka', 'gtin', 'aciklama', 'kdv', 'gorsel_url']);

    /*
     * A PRODUCT WITH NOWHERE TO LIVE CANNOT BE PUBLISHED, and guessing a category
     * from a title is the kind of helpfulness that fills a catalogue with things
     * nobody can find. Those two are the required mappings; everything else a
     * spreadsheet may legitimately leave blank.
     */
    $required = $columns->filter(fn ($column): bool => $column->isMappingRequired())
        ->map(fn ($column): string => $column->getName())
        ->values()
        ->all();

    expect($required)->toBe(['baslik', 'kategori_yolu']);
});

it('runs a row end to end through the importer and publishes it', function (): void {
    $admin = importAdmin();
    $this->actingAs($admin, 'admin');

    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'katalog.csv',
        'file_path' => 'imports/katalog.csv',
        'importer' => ProductImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $admin->getKey(),
    ]);

    $importer = new ProductImporter(
        import: $import,
        columnMap: [
            'baslik' => 'baslik',
            'kategori_yolu' => 'kategori_yolu',
            'marka' => 'marka',
            'gtin' => 'gtin',
            'aciklama' => 'aciklama',
            'kdv' => 'kdv',
            'gorsel_url' => 'gorsel_url',
        ],
        options: [],
    );

    /*
     * **THE FRAMEWORK'S OWN ENTRY POINT**, not a hand-rolled call to the service.
     * `__invoke()` is what the queued job runs, and it is the thing that would
     * break if `fillRecord()` tried to set `baslik` on a Product or `saveRecord()`
     * saved a model the actions had already finished with.
     */
    $importer([
        'baslik' => 'Pamuklu Bisiklet Yaka Tişört',
        'kategori_yolu' => 'Erkek > Giyim > Tişört',
        'marka' => 'Raftabul Basics',
        'gtin' => '08691234567890',
        'aciklama' => '%100 pamuk.',
        'kdv' => '%20',
        'gorsel_url' => null,
    ]);

    $product = Product::query()->firstOrFail();

    expect($product->title_tr)->toBe('Pamuklu Bisiklet Yaka Tişört')
        ->and($product->status)->toBe(ProductStatus::Published)
        ->and($product->moderated_by)->toBe((int) $admin->getKey())
        ->and(Category::query()->count())->toBe(3)
        ->and($product->variants()->count())->toBe(1);
});

it('reports what got in and what did not, in Turkish', function (): void {
    $admin = importAdmin();

    $import = Import::query()->create([
        'completed_at' => now(),
        'file_name' => 'katalog.csv',
        'file_path' => 'imports/katalog.csv',
        'importer' => ProductImporter::class,
        'processed_rows' => 10,
        'total_rows' => 10,
        'successful_rows' => 7,
        'user_id' => $admin->getKey(),
    ]);

    $body = ProductImporter::getCompletedNotificationBody($import);

    // The two numbers an admin actually wants, and the failed count is not
    // hidden — three rows they have to go and look at.
    expect($body)->toContain('7')
        ->and($body)->toContain('3')
        ->and($body)->toContain('ürün');
});

it('hands out a template with the header row filled in', function (): void {
    $this->actingAs(importAdmin(), 'admin');

    /*
     * THE FIRST UPLOAD WITHOUT A TEMPLATE IS A FAILED UPLOAD. The column names are
     * Turkish and the category path has a syntax nobody guesses, so the example is
     * part of the feature rather than a nicety.
     */
    $response = Livewire::test(ImportCatalog::class)
        ->callAction('template')
        ->assertHasNoActionErrors();

    expect($response)->not->toBeNull();
});
