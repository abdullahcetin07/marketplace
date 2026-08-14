<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Authorization;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationCapability;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;

/**
 * Organization's answer to the cross-context authorization port (ADR-033 §9.1).
 *
 * Store — and every future seller-owned module — asks the Core
 * `OrganizationAuthorizationContract` its business questions; this class maps
 * them onto the org's private role→capability matrix. The mapping stays here, in
 * the single source of truth; callers never see roles or capabilities.
 *
 * Ruled 2026-07-23: StoreManage is delegable to a Manager. (Domain management is
 * out of v1 scope — path-addressed stores, ADR-035 — so no domain question here.)
 *
 * @see App\Core\Domain\Contracts\OrganizationAuthorizationContract
 */
final class OrganizationAuthorization implements OrganizationAuthorizationContract
{
    public function __construct(
        private readonly OrganizationMemberRepositoryContract $members,
    ) {}

    public function canManageOrganization(int $userId, int $organizationId): bool
    {
        return $this->holds($userId, $organizationId, OrganizationCapability::StoreManage);
    }

    public function isActiveMember(int $userId, int $organizationId): bool
    {
        return $this->members->isActiveMember($organizationId, $userId);
    }

    /**
     * @return array<int, int>
     */
    public function organizationIdsForUser(int $userId): array
    {
        return $this->members->organizationIdsForUser($userId);
    }

    /**
     * Added for Offer — see the contract for why the id-speaking methods above
     * could not answer it. One indexed column read; no model leaves.
     */
    public function organizationUuidFor(int $organizationId): ?string
    {
        $uuid = Organization::query()->whereKey($organizationId)->value('uuid');

        return $uuid === null ? null : (string) $uuid;
    }

    /**
     * @return array<int, int>
     */
    public function activeMemberUserIdsFor(int $organizationId): array
    {
        return $this->members->membersOf($organizationId)
            ->filter(fn (OrganizationMember $member): bool => $member->isActive())
            ->map(fn (OrganizationMember $member): int => (int) $member->user_id)
            ->values()
            ->all();
    }

    private function holds(int $userId, int $organizationId, OrganizationCapability $capability): bool
    {
        $membership = $this->members->findMembership($organizationId, $userId);

        // ->can() already requires an ACTIVE membership.
        return $membership !== null && $membership->can($capability);
    }
}
