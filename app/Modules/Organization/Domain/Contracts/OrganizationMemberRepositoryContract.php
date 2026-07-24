<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Contracts;

use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for organization membership.
 *
 * The isolation boundary (ADR-030) is resolved here: `isActiveMember` and
 * `organizationIdsForUser` are what the seller-facing policies and repositories
 * use to confine a user to the organizations they belong to.
 *
 * @see App\Modules\Organization\Infrastructure\Repositories\OrganizationMemberRepository
 */
interface OrganizationMemberRepositoryContract
{
    public function findMembership(int $organizationId, int $userId): ?OrganizationMember;

    public function findByUuid(string $uuid): ?OrganizationMember;

    public function findOrFailByUuid(string $uuid): OrganizationMember;

    /**
     * @return Collection<int, OrganizationMember>
     */
    public function membersOf(int $organizationId): Collection;

    /**
     * The Owner membership of an organization — exactly one exists (ADR-029).
     */
    public function ownerMember(int $organizationId): ?OrganizationMember;

    /**
     * Whether the user is an ACTIVE member of the organization — the tenancy
     * gate (ADR-030).
     */
    public function isActiveMember(int $organizationId, int $userId): bool;

    /**
     * The ids of every organization the user actively belongs to — the scope a
     * seller's listing/reads are confined to.
     *
     * @return array<int, int>
     */
    public function organizationIdsForUser(int $userId): array;
}
