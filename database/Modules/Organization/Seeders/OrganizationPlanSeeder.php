<?php

declare(strict_types=1);

namespace Database\Modules\Organization\Seeders;

use App\Modules\Organization\Domain\Models\OrganizationPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The default subscription tiers (ADR-028 examples).
 *
 * Idempotent: keyed on `slug`, safe on every deploy. Operators edit limits and
 * add tiers from the admin panel afterwards — this only guarantees a sane
 * starting set exists. `store_limit` null = unlimited.
 */
final class OrganizationPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['name' => 'Starter', 'store_limit' => 1, 'sort_order' => 10],
            ['name' => 'Business', 'store_limit' => 5, 'sort_order' => 20],
            ['name' => 'Enterprise', 'store_limit' => null, 'sort_order' => 30],
        ];

        foreach ($plans as $plan) {
            // No uuid here: HasUuid generates it on create and guards the column,
            // so it stays stable across re-seeds instead of being overwritten.
            OrganizationPlan::query()->updateOrCreate(
                ['slug' => Str::slug($plan['name'])],
                [
                    'name' => $plan['name'],
                    'store_limit' => $plan['store_limit'],
                    'is_active' => true,
                    'sort_order' => $plan['sort_order'],
                ],
            );
        }
    }
}
