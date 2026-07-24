<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Repositories;

use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Membership lookup + the tenancy-isolation queries (ADR-030).
 *
 * @see App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract
 */
final class OrganizationMemberRepository implements OrganizationMemberRepositoryContract
{
    /** @var array<int, string> */
    private const array WITH = ['user'];

    public function findMembership(int $organizationId, int $userId): ?OrganizationMember
    {
        return OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->first();
    }

    public function findByUuid(string $uuid): ?OrganizationMember
    {
        return OrganizationMember::query()->with(self::WITH)->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): OrganizationMember
    {
        $member = $this->findByUuid($uuid);

        if ($member === null) {
            throw (new ModelNotFoundException)->setModel(OrganizationMember::class, [$uuid]);
        }

        return $member;
    }

    /**
     * @return Collection<int, OrganizationMember>
     */
    public function membersOf(int $organizationId): Collection
    {
        return OrganizationMember::query()
            ->with(self::WITH)
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get();
    }

    public function ownerMember(int $organizationId): ?OrganizationMember
    {
        return OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('role', OrganizationRole::Owner->value)
            ->first();
    }

    public function isActiveMember(int $organizationId, int $userId): bool
    {
        return OrganizationMember::query()
            ->active()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * @return array<int, int>
     */
    public function organizationIdsForUser(int $userId): array
    {
        return OrganizationMember::query()
            ->active()
            ->where('user_id', $userId)
            ->pluck('organization_id')
            ->all();
    }
}
