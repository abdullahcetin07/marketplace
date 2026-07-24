<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Policies;

use App\Models\User;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationCapability;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Authorises actions on organization membership — the ADR-030 isolation
 * boundary in policy form.
 *
 * NOT a `BasePolicy`: org capabilities are NOT Spatie permissions (Spatie
 * `teams` is false). Access is resolved from the ACTOR'S OWN membership of the
 * TARGET member's organization: you may act on a member only if you are an
 * active member of the same organization AND your role grants the capability.
 * A user with no membership in that org is denied by construction — that is the
 * tenancy wall. Capabilities, never role names, are checked (§5.1).
 *
 * Super-admins bypass (platform oversight); regular platform admins are not org
 * members and so do not manage org-internal membership here.
 *
 * @see docs/modules/Organization.md §5, §9; ADR-030
 */
final class OrganizationMemberPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly OrganizationMemberRepositoryContract $members,
    ) {}

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function view(User $user, OrganizationMember $target): Response
    {
        return $this->decide($user, $target, OrganizationCapability::MemberView);
    }

    public function updateRole(User $user, OrganizationMember $target): Response
    {
        if ($target->isOwner()) {
            return Response::deny(__('errors.forbidden'));
        }

        return $this->decide($user, $target, OrganizationCapability::MemberUpdateRole);
    }

    public function remove(User $user, OrganizationMember $target): Response
    {
        // The Owner can never be removed (ADR-029) — deny before the capability
        // check so even a holder of member.remove cannot.
        if ($target->isOwner()) {
            return Response::deny(__('errors.forbidden'));
        }

        return $this->decide($user, $target, OrganizationCapability::MemberRemove);
    }

    /**
     * The single decision point: the actor must be an active member of the
     * target's organization AND hold the capability there.
     */
    private function decide(User $user, OrganizationMember $target, OrganizationCapability $capability): Response
    {
        $membership = $this->members->findMembership($target->organization_id, $user->getKey());

        if ($membership === null || ! $membership->can($capability)) {
            return Response::deny(__('errors.forbidden'));
        }

        return Response::allow();
    }
}
