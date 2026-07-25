<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Modules\Audit\Domain\Models\AuditEntry;
use App\Modules\Organization\Application\Actions\ReviewDocumentAction;
use App\Modules\Organization\Application\Actions\SubmitKycAction;
use App\Modules\Organization\Application\Actions\UploadDocumentAction;
use App\Modules\Organization\Domain\DTOs\SubmitKycDTO;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentStatus;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentType;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| KYC, documents and company verification (Phase 4)
|--------------------------------------------------------------------------
|
| Country-agnostic core with a metadata bag for country-specific fields;
| encrypted personal identifiers kept out of the audit trail; documents on the
| private disk; every admin review audited with a reason.
*/

beforeEach(function (): void {
    // seedAll: roles/permissions for organization.review_documents.
    $this->seedAll();
    Storage::fake(config('marketplace.media.private_disk'));
});

it('submits KYC with country-agnostic core and a metadata extension', function (): void {
    $org = Organization::factory()->create();

    $kyc = app(SubmitKycAction::class)->run(new SubmitKycDTO(
        organizationId: $org->getKey(),
        taxNumber: '1234567890',
        registrationNumber: '654321',
        authorizedPersonName: 'Jane Doe',
        authorizedPersonNationalId: '11111111111',
        // Turkey-specific fields ride the bag — no schema column for MERSİS.
        metadata: ['mersis' => '0123456789012345', 'tax_office' => 'Kadıköy VD'],
    ));

    expect($kyc->tax_number)->toBe('1234567890')
        ->and($kyc->meta('mersis'))->toBe('0123456789012345')
        ->and($kyc->meta('tax_office'))->toBe('Kadıköy VD')
        ->and($kyc->submitted_at)->not->toBeNull();
});

it('encrypts the national id and keeps it out of the audit trail', function (): void {
    $org = Organization::factory()->create();

    $kyc = app(SubmitKycAction::class)->run(new SubmitKycDTO(
        organizationId: $org->getKey(),
        authorizedPersonName: 'Jane Doe',
        authorizedPersonNationalId: '11111111111',
    ));

    // Readable through the cast, ciphertext in the column.
    expect($kyc->authorized_person_national_id)->toBe('11111111111');
    $raw = DB::table('organization_kyc')->where('id', $kyc->getKey())->value('authorized_person_national_id');
    expect($raw)->not->toBe('11111111111');

    // Never in the audit trail.
    $entry = AuditEntry::query()->forModel($kyc)->latest('id')->first();
    expect(array_keys($entry->new_values ?? []))->not->toContain('authorized_person_national_id')
        ->and((string) json_encode($entry->new_values))->not->toContain('11111111111');
});

it('stores an uploaded document on the private disk as pending', function (): void {
    $org = Organization::factory()->create();
    // The collection sniffs the file on disk, and `create()` leaves it empty —
    // an empty file is `application/x-empty`, whatever mime the fake claims.
    // Real PDF bytes are what the accepted-mime check is actually reading.
    $file = UploadedFile::fake()->createWithContent(
        'tax.pdf',
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
    );

    $document = app(UploadDocumentAction::class)->run($org, OrganizationDocumentType::TaxCertificate, $file);

    expect($document->status)->toBe(OrganizationDocumentStatus::Pending)
        ->and($document->file())->not->toBeNull()
        // The file is on the PRIVATE disk, never a public one.
        ->and($document->file()->disk)->toBe(config('marketplace.media.private_disk'));
});

it('sends a fixable document back for revision and audits it', function (): void {
    $org = Organization::factory()->create();
    $document = OrganizationDocument::factory()->for($org)->create();
    $admin = Admin::factory()->admin()->create();

    // Revision-friendly: a blurry scan is asked to be redone, not rejected.
    app(ReviewDocumentAction::class)->run(
        $document,
        OrganizationDocumentStatus::NeedsRevision,
        'Illegible scan — please re-upload a clear copy',
        $admin,
    );

    $fresh = $document->fresh();
    expect($fresh->status)->toBe(OrganizationDocumentStatus::NeedsRevision)
        ->and($fresh->reviewed_by)->toBe($admin->getKey())
        ->and($fresh->review_notes)->toBe('Illegible scan — please re-upload a clear copy');

    $entry = AuditEntry::query()->forModel($document)->latest('id')->first();
    expect($entry->metadata)->toBe(['reason' => 'Illegible scan — please re-upload a clear copy']);
});

it('gates document review on the admin permission', function (): void {
    $org = Organization::factory()->create();
    $document = OrganizationDocument::factory()->for($org)->create();

    // Admin role holds organization.review_documents; the Support role does not.
    $reviewer = Admin::factory()->admin()->create();
    $supportOnly = Admin::factory()->support()->create();

    expect($reviewer->can('review', $document))->toBeTrue()
        ->and($supportOnly->can('review', $document))->toBeFalse();
});

it('lets a member view a document but not an outsider', function (): void {
    $org = Organization::factory()->create();
    $document = OrganizationDocument::factory()->for($org)->create();

    $member = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Viewer)
        ->create(['user_id' => $member->getKey()]);

    $outsider = Seller::factory()->create();

    expect($member->can('view', $document))->toBeTrue()
        ->and($outsider->can('view', $document))->toBeFalse();
});
