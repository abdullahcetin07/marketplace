<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentStatus;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentType;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use Illuminate\Http\UploadedFile;

/**
 * Upload a KYC / legal document for an organization.
 *
 * The file goes to the private-disk `documents` media collection — never public.
 * The document starts Pending, awaiting an admin's review. The uploaded file is
 * a framework object, so it is passed to the action directly rather than
 * wrapped in a DTO.
 *
 * Transaction OFF: the media library writes the file outside the DB transaction,
 * and rolling the row back would orphan a stored file. The row + file are
 * created together and the row is the record of truth.
 */
final class UploadDocumentAction extends BaseAction
{
    protected bool $useTransaction = false;

    public function handle(mixed ...$arguments): OrganizationDocument
    {
        /** @var Organization $organization */
        $organization = $arguments[0];
        /** @var OrganizationDocumentType $type */
        $type = $arguments[1];
        /** @var UploadedFile $file */
        $file = $arguments[2];

        $document = OrganizationDocument::query()->create([
            'organization_id' => $organization->getKey(),
            'type' => $type,
            'status' => OrganizationDocumentStatus::Pending,
        ]);

        // Lands on the private disk via the shared `documents` collection.
        $document->addMedia($file)->toMediaCollection('documents');

        return $document;
    }
}
