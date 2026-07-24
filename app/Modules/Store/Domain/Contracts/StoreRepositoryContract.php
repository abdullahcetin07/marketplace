<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Contracts;

use App\Modules\Store\Domain\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * The persistence port for stores.
 *
 * `findByOpeningRequestUuid` is the idempotency lookup (ADR-032): the creation
 * listener asks it before creating, so a redelivered `StoreOpeningApproved`
 * finds the existing store and creates nothing. `findByHost`/`findBySlug` serve
 * the public storefront resolution (ADR-034).
 *
 * @see App\Modules\Store\Infrastructure\Repositories\StoreRepository
 */
interface StoreRepositoryContract
{
    public function findByUuid(string $uuid): ?Store;

    public function findOrFailByUuid(string $uuid): Store;

    public function findBySlug(string $slug): ?Store;

    /**
     * The public-storefront lookup (ADR-034/035): an ACTIVE store by slug, with
     * its profile eager-loaded. Returns null for a missing OR non-active store —
     * one path, so the public surface never leaks that a non-live store exists.
     */
    public function findPublishedBySlug(string $slug): ?Store;

    /**
     * The idempotency key lookup — one store per approved request (ADR-032).
     */
    public function findByOpeningRequestUuid(string $requestUuid): ?Store;

    public function slugExists(string $slug): bool;

    public function storeNumberExists(string $storeNumber): bool;

    /**
     * @return Collection<int, Store>
     */
    public function forOrganization(int $organizationId): Collection;

    /**
     * Every store across the given organizations — the seller's cross-org list,
     * scoped to the orgs they belong to (ADR-030).
     *
     * @param  array<int, int>  $organizationIds
     * @return Collection<int, Store>
     */
    public function forOrganizations(array $organizationIds): Collection;

    /**
     * @return LengthAwarePaginator<int, Store>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator;
}
