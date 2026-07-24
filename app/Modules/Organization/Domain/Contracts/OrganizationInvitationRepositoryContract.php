<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Contracts;

use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for organization invitations.
 *
 * Lookup is by TOKEN HASH (ADR-031): acceptance hashes the presented token and
 * finds the row in one indexed query — the raw token is never stored.
 *
 * @see App\Modules\Organization\Infrastructure\Repositories\OrganizationInvitationRepository
 */
interface OrganizationInvitationRepositoryContract
{
    public function findByTokenHash(string $tokenHash): ?OrganizationInvitation;

    public function findByUuid(string $uuid): ?OrganizationInvitation;

    public function findOrFailByUuid(string $uuid): OrganizationInvitation;

    /**
     * The current PENDING invitation for an address in an organization, if any —
     * used to invalidate it when a new one is issued.
     */
    public function pendingFor(int $organizationId, string $email): ?OrganizationInvitation;

    /**
     * @return Collection<int, OrganizationInvitation>
     */
    public function forOrganization(int $organizationId): Collection;
}
