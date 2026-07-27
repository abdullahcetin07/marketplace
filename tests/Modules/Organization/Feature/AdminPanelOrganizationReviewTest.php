<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Organization\Application\Actions\UploadDocumentAction;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentStatus;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentType;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Events\OrganizationDocumentReviewed;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationBankAccount;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use App\Modules\Organization\Domain\Models\OrganizationKyc;
use App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource\Pages\ListOrganizations;
use App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource\Pages\ViewOrganization;
use App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource\RelationManagers\DocumentsRelationManager;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Admin panel — the organization review surface
|--------------------------------------------------------------------------
|
| An admin approving a company must be able to see what was submitted: the KYC,
| the payout account and the uploaded documents. Three things are worth pinning
| here and nothing else is:
|
|  1. The encrypted fields — the authorised person's national id and the IBAN —
|     reach the browser MASKED and never in full. Encryption at rest is worth
|     nothing if the review screen prints the plaintext.
|  2. The document reaches the reviewer as a STREAMED download, not a signed
|     URL: the private disk is `local` here, whose driver has no temporaryUrl()
|     and throws. Streaming is the access path that works on every driver.
|  3. Every review outcome is ReviewDocumentAction — the status, the reviewer,
|     the notes and the event come out identical to the admin API's.
|
| The panel is set explicitly because a Livewire test has no panel middleware to
| do it.
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Storage::fake(config('marketplace.media.private_disk'));
    Storage::fake(config('marketplace.media.public_disk'));
});

/**
 * A pending company with everything a reviewer reads: KYC, a payout account and
 * a document. The identifiers are fixed, not faked, so a test can assert that
 * the full value is absent from the response.
 */
function companyUnderReview(): Organization
{
    $organization = Organization::factory()->create([
        'legal_name' => 'Raftabul Ticaret A.Ş.',
        'status' => OrganizationStatus::Pending,
    ]);

    OrganizationKyc::factory()->for($organization)->create([
        'tax_number' => '1234567890',
        'authorized_person_name' => 'Jane Doe',
        'authorized_person_national_id' => '12345678901',
    ]);

    OrganizationBankAccount::factory()->for($organization)->create([
        'is_primary' => true,
        'account_holder' => 'Raftabul Ticaret A.Ş.',
        'bank_name' => 'Test Bankası',
        'iban' => 'TR330006100519786457841326',
    ]);

    return $organization;
}

/**
 * A REAL pdf — the media collection sniffs the bytes, so an empty placeholder
 * is rejected as `application/x-empty` whatever mime it claims.
 */
function reviewablePdf(string $name = 'vergi-levhasi.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
    );
}

/**
 * The admin documents relation manager, mounted on its owner record.
 */
function adminDocuments(Organization $organization): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(DocumentsRelationManager::class, [
        'ownerRecord' => $organization,
        'pageClass' => ViewOrganization::class,
    ]);
}

it('shows the reviewer the company, its KYC and its payout account', function (): void {
    $this->actingAsAdmin(Admin::factory()->admin()->create());
    $organization = companyUnderReview();

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Raftabul Ticaret A.Ş.')
        ->assertSee('1234567890')
        ->assertSee('Jane Doe')
        ->assertSee('Test Bankası');
});

it('masks the encrypted identifiers instead of printing them', function (): void {
    $this->actingAsAdmin(Admin::factory()->admin()->create());
    $organization = companyUnderReview();

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->assertSuccessful()
        // Enough to check the tail against the identity document…
        ->assertSee('•••• 8901')
        ->assertSee('•••• 1326')
        // …and never the value itself, which is encrypted at rest and excluded
        // from the audit trail precisely so it does not travel.
        ->assertDontSee('12345678901')
        ->assertDontSee('TR330006100519786457841326');
});

it('lists the company documents for review', function (): void {
    $this->actingAsAdmin(Admin::factory()->admin()->create());
    $organization = companyUnderReview();

    $pending = OrganizationDocument::factory()->for($organization)
        ->create(['status' => OrganizationDocumentStatus::Pending]);
    $reviewed = OrganizationDocument::factory()->for($organization)
        ->type(OrganizationDocumentType::IdDocument)
        ->create(['status' => OrganizationDocumentStatus::NeedsRevision, 'review_notes' => 'Okunmuyor.']);

    adminDocuments($organization)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$pending, $reviewed])
        ->assertSee('Okunmuyor.');
});

it('streams the private document to the reviewer, without a signed url', function (): void {
    $this->actingAsAdmin(Admin::factory()->admin()->create());
    $organization = companyUnderReview();

    $document = app(UploadDocumentAction::class)->run(
        $organization,
        OrganizationDocumentType::TaxCertificate,
        reviewablePdf('vergi-levhasi.pdf'),
    );

    // The disk here is `local`, whose driver throws on temporaryUrl(). That the
    // download succeeds IS the assertion: the bytes come through the
    // authenticated request, not a URL.
    expect($document->file()->disk)->toBe(config('marketplace.media.private_disk'));

    adminDocuments($organization)
        ->callTableAction('download', $document)
        ->assertFileDownloaded('vergi-levhasi.pdf');
});

it('approves a document through the module action', function (): void {
    $admin = $this->actingAsAdmin(Admin::factory()->admin()->create());
    $organization = companyUnderReview();
    $document = OrganizationDocument::factory()->for($organization)->create();

    Event::fake([OrganizationDocumentReviewed::class]);

    adminDocuments($organization)
        ->callTableAction('approve', $document, data: ['notes' => 'Belge okunaklı.'])
        ->assertHasNoTableActionErrors();

    $fresh = $document->fresh();

    expect($fresh->status)->toBe(OrganizationDocumentStatus::Approved)
        ->and($fresh->reviewed_by)->toBe($admin->getKey())
        ->and($fresh->review_notes)->toBe('Belge okunaklı.');

    Event::assertDispatched(OrganizationDocumentReviewed::class);
});

it('sends a fixable document back for revision with the reason', function (): void {
    $admin = $this->actingAsAdmin(Admin::factory()->admin()->create());
    $organization = companyUnderReview();
    $document = OrganizationDocument::factory()->for($organization)->create();

    adminDocuments($organization)
        ->callTableAction('request_revision', $document, data: ['notes' => 'Bulanık tarama, tekrar yükleyin.'])
        ->assertHasNoTableActionErrors();

    $fresh = $document->fresh();

    expect($fresh->status)->toBe(OrganizationDocumentStatus::NeedsRevision)
        ->and($fresh->reviewed_by)->toBe($admin->getKey())
        ->and($fresh->review_notes)->toBe('Bulanık tarama, tekrar yükleyin.');
});

it('rejects a document, and requires a reason to do it', function (): void {
    $this->actingAsAdmin(Admin::factory()->admin()->create());
    $organization = companyUnderReview();
    $document = OrganizationDocument::factory()->for($organization)->create();

    // A terminal decision the seller cannot fix is never taken silently.
    adminDocuments($organization)
        ->callTableAction('reject', $document, data: ['notes' => ''])
        ->assertHasTableActionErrors(['notes']);

    expect($document->fresh()->status)->toBe(OrganizationDocumentStatus::Pending);

    adminDocuments($organization)
        ->callTableAction('reject', $document, data: ['notes' => 'Başka bir şirkete ait.'])
        ->assertHasNoTableActionErrors();

    expect($document->fresh()->status)->toBe(OrganizationDocumentStatus::Rejected);
});

it('denies the whole surface to an admin without the review permission', function (): void {
    // Support holds neither organization.review_documents nor the super-admin
    // bypass — the relation manager is not merely button-less, it is absent.
    $support = $this->actingAsAdmin(Admin::factory()->support()->create());
    $organization = companyUnderReview();

    expect($support->can('review', new OrganizationDocument(['organization_id' => $organization->getKey()])))->toBeFalse()
        ->and(DocumentsRelationManager::canViewForRecord($organization, ViewOrganization::class))->toBeFalse();
});

it('hides every review action from an admin who may not review', function (): void {
    $this->actingAsAdmin(Admin::factory()->support()->create());
    $organization = companyUnderReview();

    $document = app(UploadDocumentAction::class)->run(
        $organization,
        OrganizationDocumentType::TaxCertificate,
        reviewablePdf(),
    );

    adminDocuments($organization)
        ->assertTableActionHidden('download', $document)
        ->assertTableActionHidden('approve', $document)
        ->assertTableActionHidden('request_revision', $document)
        ->assertTableActionHidden('reject', $document);

    expect($document->fresh()->status)->toBe(OrganizationDocumentStatus::Pending);
});

it('keeps a seller out of the admin review page entirely', function (): void {
    $this->actingAsSeller();
    $organization = companyUnderReview();

    // The admin panel binds to the `admin` guard, whose provider cannot resolve
    // a seller row at all — so this is a redirect to the admin login, not a
    // policy denial (docs/authentication.md).
    $this->get('/admin/organizations/'.$organization->getRouteKey())
        ->assertRedirect();
});

it('nudges about unreviewed documents without blocking the approval', function (): void {
    $this->actingAsAdmin(Admin::factory()->admin()->create());
    $organization = companyUnderReview();
    OrganizationDocument::factory()->for($organization)
        ->create(['status' => OrganizationDocumentStatus::Pending]);

    // The warning lives in the confirmation modal, where the decision is made.
    Livewire::test(ListOrganizations::class)
        ->assertCanSeeTableRecords([$organization])
        ->mountTableAction('approve', $organization)
        ->assertSee(__('organization.review.pending_documents_warning', ['count' => 1]));

    // …and it is a nudge, not a gate. The domain permits approving a company
    // whose documents are still outstanding, and this surface does not invent
    // a rule the domain does not have (ADR-018).
    Livewire::test(ListOrganizations::class)
        ->callTableAction('approve', $organization, data: ['reason' => 'Belgeler ayrıca incelenecek.'])
        ->assertHasNoTableActionErrors();

    expect($organization->fresh()->status)->toBe(OrganizationStatus::Approved);
});
