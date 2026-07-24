<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Contracts;

use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence port for organizations.
 *
 * Reads declare their eager loads (`$with`) — strict mode makes a lazy load
 * throw, and the resource + the limit resolver read the plan/owner/locale
 * relations.
 *
 * @see App\Modules\Organization\Infrastructure\Repositories\OrganizationRepository
 */
interface OrganizationRepositoryContract
{
    public function findByUuid(string $uuid): ?Organization;

    public function findOrFailByUuid(string $uuid): Organization;

    /**
     * The first organization a given user OWNS, if any.
     *
     * A user may own several (ADR-030 — a user belongs to many organizations);
     * `owner_id` is the canonical Owner PER organization. "Which organizations
     * does this user belong to" is a membership query (Phase 2), not this.
     */
    public function findByOwnerId(int $ownerId): ?Organization;

    public function slugExists(string $slug): bool;

    /**
     * The admin listing — all organizations, optionally filtered by status or a
     * name/slug search.
     *
     * @return LengthAwarePaginator<int, Organization>
     */
    public function paginate(?OrganizationStatus $status = null, ?string $search = null, int $perPage = 25): LengthAwarePaginator;
}
