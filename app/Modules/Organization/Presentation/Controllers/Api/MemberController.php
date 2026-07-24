<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Organization\Application\Actions\CancelInvitationAction;
use App\Modules\Organization\Application\Actions\ChangeMemberRoleAction;
use App\Modules\Organization\Application\Actions\InviteMemberAction;
use App\Modules\Organization\Application\Actions\RemoveMemberAction;
use App\Modules\Organization\Application\Actions\ResendInvitationAction;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Presentation\Requests\InviteMemberRequest;
use App\Modules\Organization\Presentation\Requests\UpdateMemberRoleRequest;
use App\Modules\Organization\Presentation\Resources\OrganizationInvitationResource;
use App\Modules\Organization\Presentation\Resources\OrganizationMemberResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Members and invitations for one organization. All authorised by org
 * capabilities via the policies — a member of another org is denied (ADR-030).
 */
final class MemberController extends BaseController
{
    public function __construct(
        private readonly OrganizationMemberRepositoryContract $members,
        private readonly InviteMemberAction $invite,
        private readonly ChangeMemberRoleAction $changeRole,
        private readonly RemoveMemberAction $remove,
        private readonly CancelInvitationAction $cancelInvite,
        private readonly ResendInvitationAction $resendInvite,
    ) {}

    /**
     * GET /organizations/{organization}/members
     */
    public function index(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        return $this->ok(
            OrganizationMemberResource::collection($this->members->membersOf($organization->getKey())),
        );
    }

    /**
     * POST /organizations/{organization}/invitations
     */
    public function invite(InviteMemberRequest $request, Organization $organization): JsonResponse
    {
        $invitation = $this->invite->run($organization, $request->toDto($organization->getKey()));

        return $this->created(
            new OrganizationInvitationResource($invitation),
            __('organization.invitation_sent'),
        );
    }

    /**
     * DELETE /organizations/{organization}/invitations/{invitation}
     */
    public function cancelInvitation(Organization $organization, OrganizationInvitation $invitation): JsonResponse
    {
        $this->authorize('cancel', $invitation);
        $this->cancelInvite->run($invitation);

        return $this->noContent();
    }

    /**
     * POST /organizations/{organization}/invitations/{invitation}/resend
     */
    public function resendInvitation(Organization $organization, OrganizationInvitation $invitation): JsonResponse
    {
        $this->authorize('resend', $invitation);
        $this->resendInvite->run($invitation);

        return $this->ok(message: __('organization.invitation_sent'));
    }

    /**
     * PATCH /organizations/{organization}/members/{member}
     */
    public function updateRole(UpdateMemberRoleRequest $request, Organization $organization, OrganizationMember $member): JsonResponse
    {
        $updated = $this->changeRole->run($member, $request->role(), $request->validated('reason'));

        return $this->ok(new OrganizationMemberResource($updated->load('user')));
    }

    /**
     * DELETE /organizations/{organization}/members/{member}
     */
    public function remove(Request $request, Organization $organization, OrganizationMember $member): JsonResponse
    {
        $this->authorize('remove', $member);

        $reason = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']])['reason'] ?? null;
        $this->remove->run($member, $reason);

        return $this->noContent();
    }
}
