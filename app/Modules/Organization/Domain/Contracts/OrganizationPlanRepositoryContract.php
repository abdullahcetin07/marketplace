<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Contracts;

use App\Modules\Organization\Domain\Models\OrganizationPlan;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for subscription plans.
 *
 * @see App\Modules\Organization\Infrastructure\Repositories\OrganizationPlanRepository
 */
interface OrganizationPlanRepositoryContract
{
    /**
     * Active plans, ordered for display.
     *
     * @return Collection<int, OrganizationPlan>
     */
    public function active(): Collection;

    public function findBySlug(string $slug): ?OrganizationPlan;
}
