<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Repositories;

use App\Modules\Organization\Domain\Contracts\StoreOpeningRequestRepositoryContract;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @see App\Modules\Organization\Domain\Contracts\StoreOpeningRequestRepositoryContract
 */
final class StoreOpeningRequestRepository implements StoreOpeningRequestRepositoryContract
{
    public function findByUuid(string $uuid): ?StoreOpeningRequest
    {
        return StoreOpeningRequest::query()->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): StoreOpeningRequest
    {
        $request = $this->findByUuid($uuid);

        if ($request === null) {
            throw (new ModelNotFoundException)->setModel(StoreOpeningRequest::class, [$uuid]);
        }

        return $request;
    }

    /**
     * @return Collection<int, StoreOpeningRequest>
     */
    public function forOrganization(int $organizationId): Collection
    {
        return StoreOpeningRequest::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get();
    }

    public function approvedCountForOrganization(int $organizationId): int
    {
        return StoreOpeningRequest::query()
            ->approved()
            ->where('organization_id', $organizationId)
            ->count();
    }

    /**
     * @return LengthAwarePaginator<int, StoreOpeningRequest>
     */
    public function pendingQueue(int $perPage = 25): LengthAwarePaginator
    {
        return StoreOpeningRequest::query()
            ->with('organization')
            ->where('status', StoreOpeningRequestStatus::Pending->value)
            ->orderBy('submitted_at')
            ->paginate(min($perPage, (int) config('marketplace.pagination.max_per_page', 100)));
    }

    /**
     * @see StoreOpeningRequestRepositoryContract::storeNameClaimed()
     */
    public function storeNameClaimed(string $storeName, ?int $exceptId = null): bool
    {
        return StoreOpeningRequest::query()
            // LOWER on both sides, the same expression the stores unique index
            // uses, so "Beko" and "beko" are one name on both surfaces.
            ->whereRaw('LOWER(store_name) = LOWER(?)', [trim($storeName)])
            ->whereIn('status', [
                StoreOpeningRequestStatus::Draft->value,
                StoreOpeningRequestStatus::Pending->value,
            ])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }
}
