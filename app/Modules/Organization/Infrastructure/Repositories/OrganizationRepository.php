<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Repositories;

use App\Modules\Organization\Domain\Contracts\OrganizationRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Organization lookup.
 *
 * @see App\Modules\Organization\Domain\Contracts\OrganizationRepositoryContract
 */
final class OrganizationRepository implements OrganizationRepositoryContract
{
    /**
     * Eager loads on every read: the limit resolver reads `plan`, and the
     * resource reads owner + locale. Strict mode makes a lazy load throw, so
     * they are declared once here.
     *
     * @var array<int, string>
     */
    private const array WITH = ['plan', 'owner', 'country', 'currency'];

    public function findByUuid(string $uuid): ?Organization
    {
        return Organization::query()->with(self::WITH)->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): Organization
    {
        $organization = $this->findByUuid($uuid);

        if ($organization === null) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$uuid]);
        }

        return $organization;
    }

    public function findByOwnerId(int $ownerId): ?Organization
    {
        return Organization::query()->with(self::WITH)->where('owner_id', $ownerId)->first();
    }

    public function slugExists(string $slug): bool
    {
        return Organization::query()->where('slug', $slug)->exists();
    }

    /**
     * @return LengthAwarePaginator<int, Organization>
     */
    public function paginate(?OrganizationStatus $status = null, ?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        return Organization::query()
            ->with(self::WITH)
            ->when($status !== null, fn ($q) => $q->where('status', $status->value))
            ->when(
                $search !== null && $search !== '',
                fn ($q) => $q->where(function ($q) use ($search): void {
                    $like = '%'.mb_strtolower($search).'%';
                    $q->whereRaw('lower(legal_name) like ?', [$like])
                        ->orWhereRaw('lower(display_name) like ?', [$like])
                        ->orWhereRaw('lower(slug) like ?', [$like]);
                }),
            )
            ->orderByDesc('id')
            ->paginate(min($perPage, (int) config('marketplace.pagination.max_per_page', 100)));
    }
}
