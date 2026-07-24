<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Repositories;

use App\Modules\Organization\Domain\Contracts\OrganizationInvitationRepositoryContract;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Invitation lookup — by token hash, uuid, or pending-for-address.
 *
 * @see App\Modules\Organization\Domain\Contracts\OrganizationInvitationRepositoryContract
 */
final class OrganizationInvitationRepository implements OrganizationInvitationRepositoryContract
{
    public function findByTokenHash(string $tokenHash): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()->with('organization')->where('token_hash', $tokenHash)->first();
    }

    public function findByUuid(string $uuid): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): OrganizationInvitation
    {
        $invitation = $this->findByUuid($uuid);

        if ($invitation === null) {
            throw (new ModelNotFoundException)->setModel(OrganizationInvitation::class, [$uuid]);
        }

        return $invitation;
    }

    public function pendingFor(int $organizationId, string $email): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->pendingFor($organizationId, mb_strtolower(trim($email)))
            ->first();
    }

    /**
     * @return Collection<int, OrganizationInvitation>
     */
    public function forOrganization(int $organizationId): Collection
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get();
    }
}
