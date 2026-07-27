<?php

declare(strict_types=1);

use App\Modules\Organization\Domain\Enums\OrganizationDocumentStatus;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentType;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages\ViewOrganization;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\RelationManagers\DocumentsRelationManager;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Seller panel — KYC document uploads
|--------------------------------------------------------------------------
|
| The relation manager owns no rule: the upload is UploadDocumentAction, which
| puts the file in the `documents` media collection (private disk by trait) and
| starts the row Pending. What is worth pinning here is that the file really
| lands on the PRIVATE disk and nowhere else, and that a seller cannot review
| their own document — approval is an admin decision.
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    // The `documents` collection resolves its disk from config at runtime.
    Storage::fake(config('marketplace.media.private_disk'));
    Storage::fake(config('marketplace.media.public_disk'));
});

/**
 * A company the seller owns, with the owner membership that carries manageKyc.
 */
function organizationOwnedBy(App\Models\Seller $seller): Organization
{
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    return $organization;
}

/**
 * A REAL pdf, not an empty placeholder.
 *
 * The `documents` collection accepts by MIME type, and the media library sniffs
 * the bytes rather than trusting the declared type — `UploadedFile::fake()
 * ->create('x.pdf', 128, 'application/pdf')` produces a zero-byte file that
 * sniffs as `application/x-empty` and is rejected. The `%PDF` magic number is
 * what makes this a document.
 */
function fakePdf(string $name = 'belge.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
    );
}

/**
 * The relation manager under test, mounted on its owner record.
 */
function documentsManager(Organization $organization): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(DocumentsRelationManager::class, [
        'ownerRecord' => $organization,
        'pageClass' => ViewOrganization::class,
    ]);
}

it('uploads a document through the action, pending review', function (): void {
    $seller = $this->actingAsSeller();
    $organization = organizationOwnedBy($seller);

    documentsManager($organization)
        ->callTableAction('create', data: [
            'type' => OrganizationDocumentType::TaxCertificate->value,
            'file' => fakePdf('vergi-levhasi.pdf'),
        ])
        ->assertHasNoTableActionErrors();

    $document = OrganizationDocument::query()
        ->where('organization_id', $organization->getKey())
        ->sole();

    expect($document->type)->toBe(OrganizationDocumentType::TaxCertificate)
        // An admin decides; the seller cannot upload something pre-approved.
        ->and($document->status)->toBe(OrganizationDocumentStatus::Pending)
        ->and($document->file())->not->toBeNull();
});

it('stores the file on the private disk, never the public one', function (): void {
    $seller = $this->actingAsSeller();
    $organization = organizationOwnedBy($seller);

    documentsManager($organization)
        ->callTableAction('create', data: [
            'type' => OrganizationDocumentType::IdDocument->value,
            'file' => fakePdf('kimlik.pdf'),
        ])
        ->assertHasNoTableActionErrors();

    $media = OrganizationDocument::query()
        ->where('organization_id', $organization->getKey())
        ->sole()
        ->file();

    expect($media)->not->toBeNull()
        ->and($media->disk)->toBe(config('marketplace.media.private_disk'))
        ->and($media->disk)->not->toBe(config('marketplace.media.public_disk'));

    // And the bytes are actually there, on that disk.
    Storage::disk(config('marketplace.media.private_disk'))
        ->assertExists("{$media->id}/{$media->file_name}");
});

it('lists the company documents with their review status', function (): void {
    $seller = $this->actingAsSeller();
    $organization = organizationOwnedBy($seller);

    $pending = OrganizationDocument::factory()->for($organization)
        ->create(['status' => OrganizationDocumentStatus::Pending]);
    $revise = OrganizationDocument::factory()->for($organization)
        ->create(['status' => OrganizationDocumentStatus::NeedsRevision, 'review_notes' => 'Okunmuyor, tekrar yükleyin.']);

    documentsManager($organization)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$pending, $revise])
        // The reviewer's note is the whole point of showing a revision row.
        ->assertSee('Okunmuyor, tekrar yükleyin.');
});

it('does not show another company\'s documents', function (): void {
    $seller = $this->actingAsSeller();
    $mine = organizationOwnedBy($seller);

    $theirs = Organization::factory()->create();
    $theirDocument = OrganizationDocument::factory()->for($theirs)->create();

    // The relation manager reads through the owner record's relation, so a
    // foreign document is not reachable by construction.
    documentsManager($mine)
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords([$theirDocument]);
});

it('hides the upload button from a member without the manage-kyc capability', function (): void {
    $viewer = $this->actingAsSeller();
    $organization = Organization::factory()->create();
    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Viewer)
        ->create(['user_id' => $viewer->getKey()]);

    expect($viewer->can('manageKyc', $organization))->toBeFalse();

    documentsManager($organization)->assertTableActionHidden('create');
});

it('offers no way for a seller to delete or approve a document', function (): void {
    $seller = $this->actingAsSeller();
    $organization = organizationOwnedBy($seller);
    $document = OrganizationDocument::factory()->for($organization)->create();

    // Reviewing is an admin decision and deleting would remove evidence mid
    // review — neither action exists on this surface.
    documentsManager($organization)
        ->assertTableActionDoesNotExist('delete')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('approve');

    expect($document->fresh()->status)->toBe($document->status);
});
