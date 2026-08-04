<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Contracts;

use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

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

    /**
     * Whether a request that is still in play already claims this store name,
     * case-insensitively.
     *
     * THE OTHER HALF OF STORE-NAME UNIQUENESS. `StoreQueryContract::storeNameExists()`
     * answers for stores that exist; this answers for names somebody is already
     * waiting on. Without it two sellers could both have a pending request for
     * "Beko", and the second would only discover the collision when an admin
     * approved the first — after the review, which is the worst moment to find
     * out.
     *
     * Only DRAFT and PENDING count. A rejected or cancelled request is not
     * holding anything, and an approved one has become a store, where the other
     * check takes over.
     *
     * @param int|null $exceptId a request to ignore — its own name is not a
     *                           collision with itself.
     */
    public function storeNameClaimed(string $storeName, ?int $exceptId = null): bool;
}
