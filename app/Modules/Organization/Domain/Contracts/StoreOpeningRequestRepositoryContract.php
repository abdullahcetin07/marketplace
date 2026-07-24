<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Contracts;

use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence port for Store Opening Requests.
 *
 * @see App\Modules\Organization\Infrastructure\Repositories\StoreOpeningRequestRepository
 */
interface StoreOpeningRequestRepositoryContract
{
    public function findByUuid(string $uuid): ?StoreOpeningRequest;

    public function findOrFailByUuid(string $uuid): StoreOpeningRequest;

    /**
     * @return Collection<int, StoreOpeningRequest>
     */
    public function forOrganization(int $organizationId): Collection;

    /**
     * How many APPROVED requests an organization has — the store tally used by
     * the limit check until the Store module owns the real count (§2.1).
     */
    public function approvedCountForOrganization(int $organizationId): int;

    /**
     * The admin review queue — pending requests across all organizations.
     *
     * @return LengthAwarePaginator<int, StoreOpeningRequest>
     */
    public function pendingQueue(int $perPage = 25): LengthAwarePaginator;
}
