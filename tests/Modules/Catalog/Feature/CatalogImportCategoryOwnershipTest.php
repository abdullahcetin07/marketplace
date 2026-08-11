<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Catalog\Application\Actions\UpdateCategoryAction;
use App\Modules\Catalog\Application\Import\CatalogRowImporter;
use App\Modules\Catalog\Domain\DTOs\UpdateCategoryDTO;
use App\Modules\Catalog\Domain\Exceptions\CatalogImportException;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Presentation\Filament\Imports\ProductImporter;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;

/*
|--------------------------------------------------------------------------
| ADR-075 — a default is not a decision
|--------------------------------------------------------------------------
|
| The first real catalogue sells a product AT a node that is also a parent:
|
|     satır A:  Cilt Bakımı > Cilt Temizleme Ürünleri > Cilt Temizleyiciler
|     satır B:  Cilt Bakımı > Cilt Temizleme Ürünleri
|
| Row A creates the middle node closed — "shelves, not shelves' contents" — and
| row B then terminates there and is refused by a flag the import itself set
| seconds earlier. Five rows failed that way, and the rejection drove 29,074 job
| retries because it escaped to the chunk.
|
| **`accepts_products = false` means two different things depending on who set
| it**, and `created_by_import` is what tells them apart. Everything below is that
| one sentence, from both sides.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A row, keyed as Filament hands it over. Named for this file: Pest shares ONE
 * global function namespace.
 *
 * @return array<string, string|null>
 */
function ownershipRow(string $path, string $gtin, string $title = 'Ürün'): array
{
    return [
        'baslik' => $title,
        'kategori_yolu' => $path,
        'marka' => null,
        'gtin' => $gtin,
        'aciklama' => null,
        'kdv' => '20',
        'gorsel_url' => null,
    ];
}

function ownershipAdmin(): int
{
    /** @var Admin $admin */
    $admin = Admin::factory()->create();
    $admin->assignRole(config('marketplace.roles.super_admin'));

    return (int) $admin->getKey();
}

it('opens a node the import itself closed, when a later row sells there', function (): void {
    $adminId = ownershipAdmin();
    $importer = app(CatalogRowImporter::class);

    // Row A: the middle node is created as an intermediate — closed by default.
    $importer->import(
        ownershipRow('Cilt Bakımı > Cilt Temizleme Ürünleri > Cilt Temizleyiciler', '08690000000201'),
        $adminId,
    );

    $middle = Category::query()->whereRaw('LOWER(name_tr) = LOWER(?)', ['Cilt Temizleme Ürünleri'])->firstOrFail();

    expect($middle->acceptsProducts())->toBeFalse()
        ->and($middle->created_by_import)->toBeTrue();

    // Row B sells directly at it.
    $product = $importer->import(
        ownershipRow('Cilt Bakımı > Cilt Temizleme Ürünleri', '08690000000202'),
        $adminId,
    );

    /*
     * **THE ROW SUCCEEDS AND THE NODE OPENS.** Nobody decided this category was
     * closed — the import applied a default while walking a longer path — so
     * ADR-075 lets the import correct itself.
     */
    expect($product->category?->getKey())->toBe($middle->getKey())
        ->and($middle->fresh()->acceptsProducts())->toBeTrue()
        // AND IT IS STILL THE IMPORT'S, so a human edit can still take it over.
        ->and($middle->fresh()->created_by_import)->toBeTrue()
        // The child from row A is untouched: opening a node does not flatten it.
        ->and(Category::query()->whereRaw('LOWER(name_tr) = LOWER(?)', ['Cilt Temizleyiciler'])->exists())->toBeTrue()
        ->and(Product::query()->count())->toBe(2);
});

it('still refuses a node a HUMAN left closed — ADR-047 stands', function (): void {
    $adminId = ownershipAdmin();

    $parent = Category::factory()->create(['name_tr' => 'Elektronik', 'accepts_products' => false]);
    $closed = Category::factory()->childOf($parent)->create([
        'name_tr' => 'Telefon',
        'accepts_products' => false,
        // The default, and the point: anything a human made is human-owned.
        'created_by_import' => false,
    ]);

    expect(fn () => app(CatalogRowImporter::class)->import(
        ownershipRow('Elektronik > Telefon', '08690000000203'),
        $adminId,
    ))->toThrow(CatalogImportException::class);

    // Closed is closed. A spreadsheet does not overturn a Category Manager.
    expect($closed->fresh()->acceptsProducts())->toBeFalse()
        ->and(Product::query()->count())->toBe(0);
});

it('hands ownership to the human the moment they edit it', function (): void {
    $adminId = ownershipAdmin();
    $importer = app(CatalogRowImporter::class);

    $importer->import(ownershipRow('Kozmetik > Bakım > Krem', '08690000000204'), $adminId);

    $middle = Category::query()->whereRaw('LOWER(name_tr) = LOWER(?)', ['Bakım'])->firstOrFail();
    expect($middle->created_by_import)->toBeTrue();

    // A human curates it — here, only a rename. Any edit counts.
    app(UpdateCategoryAction::class)->run($middle, new UpdateCategoryDTO(
        name: ['tr' => 'Bakım Ürünleri', 'en' => null],
        present: [],
    ));

    expect($middle->fresh()->created_by_import)->toBeFalse()
        ->and($middle->fresh()->acceptsProducts())->toBeFalse();

    /*
     * **AND NOW IT BEHAVES LIKE ANY HUMAN-CLOSED NODE.** Without this, a
     * re-import could reopen a category an admin had deliberately kept closed —
     * ADR-047 broken through the back door, by a spreadsheet, silently.
     */
    expect(fn () => $importer->import(
        ownershipRow('Kozmetik > Bakım Ürünleri', '08690000000205'),
        $adminId,
    ))->toThrow(CatalogImportException::class);
});

it('does not care which order the two rows arrive in', function (): void {
    $adminId = ownershipAdmin();
    $importer = app(CatalogRowImporter::class);

    // The reverse of the first test: the shallow row first, then the nested one.
    $shallow = $importer->import(ownershipRow('Cilt Bakımı > Cilt Temizleme Ürünleri', '08690000000206'), $adminId);
    $deep = $importer->import(
        ownershipRow('Cilt Bakımı > Cilt Temizleme Ürünleri > Cilt Temizleyiciler', '08690000000207'),
        $adminId,
    );

    $middle = Category::query()->whereRaw('LOWER(name_tr) = LOWER(?)', ['Cilt Temizleme Ürünleri'])->firstOrFail();

    // A node may accept products AND have children — ADR-047 always allowed it.
    expect($middle->acceptsProducts())->toBeTrue()
        ->and($shallow->category?->getKey())->toBe($middle->getKey())
        ->and($deep->category?->parent?->getKey())->toBe($middle->getKey());
});

it('changes nothing when the same file is imported twice', function (): void {
    $adminId = ownershipAdmin();
    $importer = app(CatalogRowImporter::class);

    $rows = [
        ownershipRow('Cilt Bakımı > Cilt Temizleme Ürünleri > Cilt Temizleyiciler', '08690000000208'),
        ownershipRow('Cilt Bakımı > Cilt Temizleme Ürünleri', '08690000000209'),
    ];

    foreach ($rows as $row) {
        $importer->import($row, $adminId);
    }

    $categories = Category::query()->count();
    $products = Product::query()->count();

    foreach ($rows as $row) {
        $importer->import($row, $adminId);
    }

    // The property that makes a corrected re-upload the intended workflow.
    expect(Category::query()->count())->toBe($categories)
        ->and(Product::query()->count())->toBe($products)
        ->and(Category::query()->whereRaw('LOWER(name_tr) = LOWER(?)', ['Cilt Temizleme Ürünleri'])
            ->firstOrFail()->created_by_import)->toBeTrue();
});

it('translates a domain refusal into the row failure Filament records', function (): void {
    $adminId = ownershipAdmin();

    $parent = Category::factory()->create(['name_tr' => 'Elektronik', 'accepts_products' => false]);
    Category::factory()->childOf($parent)->create([
        'name_tr' => 'Telefon',
        'accepts_products' => false,
        'created_by_import' => false,
    ]);

    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'katalog.csv',
        'file_path' => 'imports/katalog.csv',
        'importer' => ProductImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $adminId,
    ]);

    $importer = new ProductImporter($import, array_combine(
        ['baslik', 'kategori_yolu', 'marka', 'gtin', 'aciklama', 'kdv', 'gorsel_url'],
        ['baslik', 'kategori_yolu', 'marka', 'gtin', 'aciklama', 'kdv', 'gorsel_url'],
    ), []);

    /*
     * **THE ONE LINE THAT ENDED THE RETRY STORM.** `ImportCsv` catches
     * `RowImportFailedException` per row, logs it WITH the message and carries on.
     * Any other Throwable is logged with NO message, collected, and rethrown by
     * `handleExceptions()` — which fails the job, so the queue retries the whole
     * chunk. That is how five bad rows became 29,074 attempts and ~155,000
     * duplicate failure rows, all with an empty reason.
     */
    // `__invoke()` IS the entry point the queued chunk calls, so the assertion is
    // about the path production takes rather than a method in isolation.
    expect(fn () => $importer(ownershipRow('Elektronik > Telefon', '08690000000210')))
        ->toThrow(RowImportFailedException::class, 'ürün kabul etmiyor');
});
