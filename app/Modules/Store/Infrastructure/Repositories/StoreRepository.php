<?php

declare(strict_types=1);

namespace App\Modules\Store\Infrastructure\Repositories;

use App\Modules\Store\Domain\Contracts\StoreRepositoryContract;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Eager-loads the locale relations on every read: strict mode makes a lazy load
 * throw, so the storefront's language/currency/timezone are declared here rather
 * than at the call site (CLAUDE.md "strict mode is on").
 *
 * @see App\Modules\Store\Domain\Contracts\StoreRepositoryContract
 */
final class StoreRepository implements StoreRepositoryContract
{
    /**
     * @var list<string>
     */
    private array $with = ['defaultLanguage', 'defaultCurrency', 'timezone'];

    public function findByUuid(string $uuid): ?Store
    {
        return Store::query()->with($this->with)->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): Store
    {
        $store = $this->findByUuid($uuid);

        if ($store === null) {
            throw (new ModelNotFoundException)->setModel(Store::class, [$uuid]);
        }

        return $store;
    }

    public function findBySlug(string $slug): ?Store
    {
        return Store::query()->with($this->with)->where('slug', $slug)->first();
    }

    public function findPublishedBySlug(string $slug): ?Store
    {
        return Store::query()
            ->with([...$this->with, 'branding', 'seo', 'contact', 'branding.media'])
            ->publiclyVisible()
            ->where('slug', $slug)
            ->first();
    }

    public function findByOpeningRequestUuid(string $requestUuid): ?Store
    {
        return Store::query()->where('opening_request_uuid', $requestUuid)->first();
    }

    public function slugExists(string $slug): bool
    {
        return Store::query()->where('slug', $slug)->exists();
    }

    public function storeNumberExists(string $storeNumber): bool
    {
        return Store::query()->where('store_number', $storeNumber)->exists();
    }

    /**
     * @return Collection<int, Store>
     */
    public function forOrganization(int $organizationId): Collection
    {
        return Store::query()
            ->with($this->with)
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param array<int, int> $organizationIds
     *
     * @return Collection<int, Store>
     */
    public function forOrganizations(array $organizationIds): Collection
    {
        if ($organizationIds === []) {
            return Store::query()->whereRaw('1 = 0')->get();
        }

        return Store::query()
            ->with($this->with)
            ->whereIn('organization_id', $organizationIds)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Store>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return Store::query()
            ->with($this->with)
            ->orderByDesc('id')
            ->paginate(min($perPage, (int) config('marketplace.pagination.max_per_page', 100)));
    }
}
