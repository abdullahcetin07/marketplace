<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Repositories;

use App\Modules\Organization\Domain\Contracts\OrganizationPlanRepositoryContract;
use App\Modules\Organization\Domain\Models\OrganizationPlan;
use Illuminate\Database\Eloquent\Collection;

/**
 * Plan lookup.
 *
 * @see App\Modules\Organization\Domain\Contracts\OrganizationPlanRepositoryContract
 */
final class OrganizationPlanRepository implements OrganizationPlanRepositoryContract
{
    /**
     * @return Collection<int, OrganizationPlan>
     */
    public function active(): Collection
    {
        return OrganizationPlan::query()->active()->orderBy('sort_order')->get();
    }

    public function findBySlug(string $slug): ?OrganizationPlan
    {
        return OrganizationPlan::query()->where('slug', $slug)->first();
    }
}
