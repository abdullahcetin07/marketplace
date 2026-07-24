<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Organization\Application\Actions\RegisterOrganizationAction;
use App\Modules\Organization\Application\Actions\SubmitKycAction;
use App\Modules\Organization\Application\Actions\TransferOwnershipAction;
use App\Modules\Organization\Application\Actions\UpdateOrganizationAction;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Presentation\Requests\RegisterOrganizationRequest;
use App\Modules\Organization\Presentation\Requests\SubmitKycRequest;
use App\Modules\Organization\Presentation\Requests\TransferOwnershipRequest;
use App\Modules\Organization\Presentation\Requests\UpdateOrganizationRequest;
use App\Modules\Organization\Presentation\Resources\OrganizationResource;
use Illuminate\Http\JsonResponse;

/**
 * The seller-facing organization surface (ADR-030 — a user may belong to several
 * organizations, so every endpoint is scoped to one by UUID and gated by
 * membership).
 */
final class OrganizationController extends BaseController
{
    private const array WITH = ['plan', 'country', 'currency'];

    public function __construct(
        private readonly OrganizationMemberRepositoryContract $members,
        private readonly RegisterOrganizationAction $register,
        private readonly UpdateOrganizationAction $updateOrg,
        private readonly SubmitKycAction $submitKyc,
        private readonly TransferOwnershipAction $transfer,
    ) {}

    /**
     * GET /api/v1/organizations — the organizations the actor belongs to.
     */
    public function index(): JsonResponse
    {
        $ids = $this->members->organizationIdsForUser((int) current_actor()?->getKey());

        $organizations = Organization::query()->with(self::WITH)->whereIn('id', $ids)->get();

        return $this->ok(OrganizationResource::collection($organizations));
    }

    /**
     * POST /api/v1/organizations
     */
    public function store(RegisterOrganizationRequest $request): JsonResponse
    {
        $organization = $this->register->run($request->toDto());

        return $this->created(
            new OrganizationResource($organization->load(self::WITH)),
            __('organization.registered'),
        );
    }

    /**
     * GET /api/v1/organizations/{organization}
     */
    public function show(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        return $this->ok(new OrganizationResource($organization->load(self::WITH)));
    }

    /**
     * PATCH /api/v1/organizations/{organization}
     */
    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $updated = $this->updateOrg->run($organization, $request->toDto());

        return $this->ok(new OrganizationResource($updated->load(self::WITH)));
    }

    /**
     * POST /api/v1/organizations/{organization}/kyc
     */
    public function submitKyc(SubmitKycRequest $request, Organization $organization): JsonResponse
    {
        $this->submitKyc->run($request->toDto($organization->getKey()));

        return $this->ok(message: __('organization.kyc_submitted'));
    }

    /**
     * POST /api/v1/organizations/{organization}/ownership/transfer
     */
    public function transferOwnership(TransferOwnershipRequest $request, Organization $organization): JsonResponse
    {
        $target = $this->members->findOrFailByUuid((string) $request->validated('member'));

        $this->transfer->run($organization, $target, $request->validated('reason'), $request->demotedRole());

        return $this->ok(message: __('organization.ownership_transferred'));
    }
}
