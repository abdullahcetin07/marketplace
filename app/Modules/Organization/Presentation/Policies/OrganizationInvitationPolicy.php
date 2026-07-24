<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Policies;

use App\Models\User;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationCapability;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Authorises invitation actions (ADR-030/031).
 *
 * Two audiences, two rules:
 *
 *  - **Management** (cancel, resend) is an ORG CAPABILITY: the actor must be an
 *    active member of the invitation's organization holding `invitation.manage`.
 *    This is the same tenancy wall as membership — you cannot manage another
 *    org's invitations.
 *  - **Response** (accept, reject) belongs to the RECIPIENT: the authenticated
 *    account's email must match the invited address. Holding the link is not
 *    enough — the intended person, signed in, is.
 *
 * @see docs/modules/Organization.md §6
 */
final class OrganizationInvitationPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly OrganizationMemberRepositoryContract $members,
    ) {}

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function cancel(User $user, OrganizationInvitation $invitation): Response
    {
        return $this->manages($user, $invitation);
    }

    public function resend(User $user, OrganizationInvitation $invitation): Response
    {
        return $this->manages($user, $invitation);
    }

    public function accept(User $user, OrganizationInvitation $invitation): Response
    {
        return $this->isRecipient($user, $invitation);
    }

    public function reject(User $user, OrganizationInvitation $invitation): Response
    {
        return $this->isRecipient($user, $invitation);
    }

    private function manages(User $user, OrganizationInvitation $invitation): Response
    {
        $membership = $this->members->findMembership($invitation->organization_id, $user->getKey());

        if ($membership === null || ! $membership->can(OrganizationCapability::InvitationManage)) {
            return Response::deny(__('errors.forbidden'));
        }

        return Response::allow();
    }

    private function isRecipient(User $user, OrganizationInvitation $invitation): Response
    {
        return mb_strtolower(trim($user->email)) === $invitation->email
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }
}
