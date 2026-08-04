<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Models;

use App\Shared\Traits\HasUuid;
use Database\Modules\Organization\Factories\OrganizationPlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscription tier that sets how many Stores an Organization may open.
 *
 * A LOOKUP TABLE, not an enum (ADR-028 / CLAUDE.md "enum or lookup table"): an
 * operator adds, disables or re-limits a plan without a release. Uses
 * `is_active`, not `status` (ADR-015).
 *
 * `store_limit` is **nullable** — null means **unlimited** (the Enterprise
 * tier). That is why limit resolution keys on the SOURCE, not on a null value:
 * a null here is a real answer, not "unset".
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property int|null $store_limit null = unlimited
 * @property bool $is_active
 * @property int $sort_order
 *
 * @see docs/modules/Organization.md §2.8
 */
final class OrganizationPlan extends Model
{
    /** @use HasFactory<OrganizationPlanFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'organization_plans';

    protected $fillable = [
        'name',
        'slug',
        'store_limit',
        'is_active',
        'sort_order',
    ];

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isUnlimited(): bool
    {
        return $this->store_limit === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'store_limit' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
