<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Organization\Application\Actions\UploadDocumentAction;
use App\Modules\Organization\Domain\Contracts\OrganizationDocumentRepositoryContract;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Presentation\Requests\UploadDocumentRequest;
use App\Modules\Organization\Presentation\Resources\OrganizationDocumentResource;
use Illuminate\Http\JsonResponse;

/**
 * KYC document uploads (private disk). Listing is gated on membership; upload on
 * the ManageKyc capability.
 */
final class DocumentController extends BaseController
{
    public function __construct(
        private readonly OrganizationDocumentRepositoryContract $documents,
        private readonly UploadDocumentAction $upload,
    ) {}

    /**
     * GET /organizations/{organization}/documents
     */
    public function index(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        return $this->ok(
            OrganizationDocumentResource::collection($this->documents->forOrganization($organization->getKey())),
        );
    }

    /**
     * POST /organizations/{organization}/documents
     */
    public function store(UploadDocumentRequest $request, Organization $organization): JsonResponse
    {
        $document = $this->upload->run($organization, $request->documentType(), $request->file('file'));

        return $this->created(
            new OrganizationDocumentResource($document),
            __('organization.document_uploaded'),
        );
    }
}
