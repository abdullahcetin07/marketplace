<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Offer\Presentation\Filament\Seller\Imports\OfferImporter;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Actions\Imports\Models\Import;

/*
|--------------------------------------------------------------------------
| "Raporu indir" — whoever may SEE the upload may take its report
|--------------------------------------------------------------------------
|
| Yükleme Geçmişi lists the SHOP's uploads, not the person's, but Filament's own
| download allowed the uploader alone: an owner could read that their employee's
| file failed 1,746 rows and not why (2026-09-18). The page and the download now
| answer the same question, and this file is what keeps them answering it.
|
| The other half matters more: the report carries barcodes, prices and stock
| levels. A seller from another company gets 404 — the same answer as an id that
| does not exist, because telling them apart confirms which ones do.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * @return array{seller: Seller, org: Organization}
 */
function importShop(): array
{
    /** @var Seller $seller */
    $seller = Seller::factory()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    return ['seller' => $seller, 'org' => $organization];
}

function employeeOf(Organization $organization): Seller
{
    /** @var Seller $employee */
    $employee = Seller::factory()->create();

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Warehouse)
        ->create(['user_id' => $employee->getKey()]);

    return $employee;
}

function feedImportBy(Seller $uploader, string $importer = OfferImporter::class): Import
{
    /** @var Import $import */
    $import = Import::query()->create([
        'user_id' => $uploader->getKey(),
        'file_name' => 'fiyat-listesi.csv',
        'file_path' => 'imports/fiyat-listesi.csv',
        'importer' => $importer,
        'total_rows' => 2,
        'processed_rows' => 2,
        'successful_rows' => 1,
    ]);

    FailedImportRow::query()->create([
        'import_id' => $import->getKey(),
        'data' => ['Barkod' => '8690000000017', 'Fiyat' => '129,90', 'Stok' => '5'],
        'validation_error' => 'Bu barkod yayındaki katalogda yok: 8690000000017',
    ]);

    return $import;
}

function reportUrl(Import $import): string
{
    return route('seller.offer-imports.failures', ['import' => $import]);
}

it('gives the uploader their own report', function (): void {
    $shop = importShop();
    $import = feedImportBy($shop['seller']);

    $response = $this->actingAs($shop['seller'], 'seller')->get(reportUrl($import))->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('hatali-satirlar-');
});

it("gives the owner their employee's report — the whole point", function (): void {
    $shop = importShop();
    $employee = employeeOf($shop['org']);
    $import = feedImportBy($employee);

    $csv = $this->actingAs($shop['seller'], 'seller')->get(reportUrl($import))->assertOk()->streamedContent();

    // The row and its reason, which is what the seller came for.
    expect($csv)->toContain('8690000000017')
        ->and($csv)->toContain('Bu barkod yayındaki katalogda yok');
});

it("gives an employee the owner's report too — the page shows them both", function (): void {
    $shop = importShop();
    $employee = employeeOf($shop['org']);
    $import = feedImportBy($shop['seller']);

    $this->actingAs($employee, 'seller')->get(reportUrl($import))->assertOk();
});

it("refuses another company's report, as if it did not exist", function (): void {
    $mine = importShop();
    $theirs = importShop();
    $import = feedImportBy($theirs['seller']);

    // 404, not 403: this report carries their barcodes, prices and stock.
    $this->actingAs($mine['seller'], 'seller')->get(reportUrl($import))->assertNotFound();
});

it('refuses an admin catalogue import, however it was reached', function (): void {
    $shop = importShop();

    // Same uploader, different importer: the catalogue import (ADR-074) is the
    // platform's work and its report is not a seller's to read.
    $import = feedImportBy($shop['seller'], 'App\Modules\Catalog\Presentation\Filament\Imports\ProductImporter');

    $this->actingAs($shop['seller'], 'seller')->get(reportUrl($import))->assertNotFound();
});

it('turns a signed-out visitor away', function (): void {
    $shop = importShop();
    $import = feedImportBy($shop['seller']);

    $this->get(route('seller.offer-imports.failures', ['import' => $import]))
        ->assertRedirect();
});
