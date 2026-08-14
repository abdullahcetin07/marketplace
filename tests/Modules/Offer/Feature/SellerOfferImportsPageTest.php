<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Offer\Presentation\Filament\Seller\Imports\OfferImporter;
use App\Modules\Offer\Presentation\Filament\Seller\Pages\OfferImports;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| "Yükleme Geçmişi" — the report a seller can go back to
|--------------------------------------------------------------------------
|
| A completion toast is announced once and scrolls away. A seller who uploaded
| 3,525 rows and was told 3,413 failed needs the WHY, later, from a page.
|
*/

beforeEach(function (): void {
    $this->seedAll();

    // The page reads panel state (breadcrumbs, navigation); without a current
    // panel Filament resolves null and the render dies before any assertion.
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * One import belonging to a seller, with `$failed` recorded failures.
 *
 * Named for this file: Pest shares ONE global function namespace.
 */
function importsPageRecord(Seller $seller, int $failed, string $reason = 'Bu barkod yayındaki katalogda yok: 1'): Import
{
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => now(),
        'file_name' => 'fiyat-listesi.csv',
        'file_path' => 'imports/fiyat-listesi.csv',
        'importer' => OfferImporter::class,
        'processed_rows' => $failed + 2,
        'total_rows' => $failed + 2,
        'successful_rows' => 2,
        'user_id' => $seller->getKey(),
    ]);

    for ($i = 0; $i < $failed; $i++) {
        $import->failedRows()->create([
            'data' => ['gtin' => '869000000000'.$i, 'fiyat' => '10,00', 'stok' => '1'],
            'validation_error' => $reason,
        ]);
    }

    return $import;
}

it('shows a colleague’s upload, because the price list is the shop’s work', function (): void {
    /*
     * **A PRICE LIST IS THE SHOP'S WORK, NOT THE PERSON'S** (2026-08-14). Scoping
     * this page to `user_id` was invisible while every organization had one member
     * and wrong the day it had two: a Seller Employee uploads the file and the
     * owner opens an empty page with no way to learn what happened.
     */
    /** @var Seller $owner */
    $owner = Seller::factory()->create();
    /** @var Seller $employee */
    $employee = Seller::factory()->create();
    // A warehouse hand is exactly the person who uploads a stock file and is not
    // the person who later asks what happened to it.

    $organization = Organization::factory()->create(['owner_id' => $owner->getKey()]);

    foreach ([[$owner, OrganizationRole::Owner], [$employee, OrganizationRole::Warehouse]] as [$user, $role]) {
        OrganizationMember::factory()->for($organization)->role($role)->create(['user_id' => $user->getKey()]);
    }

    $theirs = importsPageRecord($employee, failed: 2);

    $this->actingAs($owner, 'seller');

    Livewire::test(OfferImports::class)->assertCanSeeTableRecords([$theirs]);
});

it('shows the seller their own uploads and nobody else’s', function (): void {
    /** @var Seller $mine */
    $mine = Seller::factory()->create();
    /** @var Seller $theirs */
    $theirs = Seller::factory()->create();

    importsPageRecord($mine, failed: 3);
    importsPageRecord($theirs, failed: 3);

    /*
     * **THE SCOPE IS THE WHOLE SECURITY MODEL OF THIS PAGE.** It reads a vendor
     * table with no tenancy of its own, so `user_id` is the only thing standing
     * between one merchant's failure report and another's — and that report
     * carries their barcodes, prices and stock levels.
     */
    $this->actingAs($mine, 'seller');

    /*
     * **THE HALF THAT MATTERS.** The widening above goes exactly as far as shared
     * membership: these two sellers share none, and a failure report carries the
     * uploader's barcodes, prices and stock levels. `imports` is a vendor table
     * with no tenancy of its own, so this query is the only thing between them.
     */
    Livewire::test(OfferImports::class)
        ->assertCanSeeTableRecords(Import::query()->where('user_id', $mine->getKey())->get())
        ->assertCanNotSeeTableRecords(Import::query()->where('user_id', $theirs->getKey())->get());
});

it('hides the admin catalogue import, which is not a seller’s upload', function (): void {
    /** @var Seller $seller */
    $seller = Seller::factory()->create();

    importsPageRecord($seller, failed: 1);

    /** @var Import $catalog */
    $catalog = Import::query()->create([
        'completed_at' => now(),
        'file_name' => 'katalog.xlsx',
        'file_path' => 'imports/katalog.xlsx',
        // A different importer entirely — ADR-074's, which loads products.
        'importer' => 'App\\Modules\\Catalog\\Presentation\\Filament\\Imports\\ProductImporter',
        'processed_rows' => 5,
        'total_rows' => 5,
        'successful_rows' => 5,
        'user_id' => $seller->getKey(),
    ]);

    $this->actingAs($seller, 'seller');

    Livewire::test(OfferImports::class)
        ->assertCanNotSeeTableRecords([$catalog]);
});

it('counts failures without lazy loading a relation per row', function (): void {
    /** @var Seller $seller */
    $seller = Seller::factory()->create();

    /*
     * TWO IMPORTS, NOT ONE. Strict mode arms the lazy-loading guard only when a
     * query hydrates more than one row (`Builder::hydrate()`), so a single-record
     * fixture renders a lazy count happily and proves nothing.
     */
    importsPageRecord($seller, failed: 4);
    importsPageRecord($seller, failed: 7);

    $this->actingAs($seller, 'seller');

    Livewire::test(OfferImports::class)
        ->assertSuccessful()
        ->assertSee('4')
        ->assertSee('7');
});
