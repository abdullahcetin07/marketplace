<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Controllers\Api\Admin;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Organization\Application\Actions\ApproveOrganizationAction;
use App\Modules\Organization\Application\Actions\RejectOrganizationAction;
use App\Modules\Organization\Application\Actions\RestoreOrganizationAction;
use App\Modules\Organization\Application\Actions\ReviewDocumentAction;
use App\Modules\Organization\Application\Actions\SetStoreLimitAction;
use App\Modules\Organization\Application\Actions\SuspendOrganizationAction;
use App\Modules\Organization\Domain\Contracts\OrganizationRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use App\Modules\Organization\Presentation\Requests\Admin\ReviewDocumentRequest;
use App\Modules\Organization\Presentation\Requests\Admin\SetStoreLimitRequest;
use App\Modules\Organization\Presentation\Resources\OrganizationDocumentResource;
use App\Modules\Organization\Presentation\Resources\OrganizationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin organization surface — the KYC review + lifecycle queue. Every
 * action is policy-gated and audited with the admin's reason.
 */
final class OrganizationController extends BaseController
{
    private const array WITH = ['plan', 'country', 'currency', 'owner'];

    public function __construct(
        private readonly OrganizationRepositoryContract $organizations,
        private readonly ApproveOrganizationAction $approve,
        private readonly RejectOrganizationAction $reject,
        private readonly SuspendOrganizationAction $suspend,
        private readonly RestoreOrganizationAction $restore,
        private readonly SetStoreLimitAction $setLimit,
        private readonly ReviewDocumentAction $reviewDocument,
    ) {}

    /**
     * GET /api/v1/admin/organizations
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);

        $paginator = $this->organizations->paginate(
            status: OrganizationStatus::tryFrom((string) $request->query('status', '')),
            search: $request->query('search') !== null ? (string) $request->query('search') : null,
            perPage: $this->perPage(),
        );

        return $this->paginated($paginator, OrganizationResource::collection($paginator->getCollection()));
    }

    /**
     * GET /api/v1/admin/organizations/{organization}
     */
    public function show(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        return $this->ok(new OrganizationResource($organization->load(self::WITH)));
    }

    public function approve(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('approve', $organization);
        $this->approve->run($organization, $this->admin(), $this->reason($request));

        return $this->ok(message: __('organization.approved'));
    }

    public function reject(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('reject', $organization);
        $this->reject->run($organization, $this->admin(), $this->reason($request, required: true));

        return $this->ok(message: __('organization.rejected'));
    }

    public function suspend(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('suspend', $organization);
        $this->suspend->run($organization, $this->admin(), $this->reason($request, required: true));

        return $this->ok(message: __('organization.suspended'));
    }

    public function reinstate(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('reinstate', $organization);
        $this->restore->run($organization, $this->admin(), $this->reason($request));

        return $this->ok(message: __('organization.reinstated'));
    }

    /**
     * PATCH /api/v1/admin/organizations/{organization}/store-limit
     */
    public function setStoreLimit(SetStoreLimitRequest $request, Organization $organization): JsonResponse
    {
        $updated = $this->setLimit->run(
            $organization,
            $request->validated('override') !== null ? (int) $request->validated('override') : null,
            $request->validated('plan_id') !== null ? (int) $request->validated('plan_id') : null,
            $request->validated('reason'),
        );

        return $this->ok(new OrganizationResource($updated->load(self::WITH)));
    }

    /**
     * POST /api/v1/admin/organizations/{organization}/documents/{document}/review
     */
    public function reviewDocument(ReviewDocumentRequest $request, Organization $organization, OrganizationDocument $document): JsonResponse
    {
        $reviewed = $this->reviewDocument->run(
            $document,
            $request->decision(),
            $request->validated('notes'),
            $this->admin(),
        );

        return $this->ok(new OrganizationDocumentResource($reviewed));
    }

    private function admin(): \App\Models\User
    {
        return current_actor();
    }

    private function reason(Request $request, bool $required = false): ?string
    {
        $rules = ['reason' => [$required ? 'required' : 'sometimes', 'nullable', 'string', 'max:1000']];

        return $request->validate($rules)['reason'] ?? null;
    }
}
