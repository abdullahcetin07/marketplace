<?php

declare(strict_types=1);

namespace Database\Modules\Organization\Factories;

use App\Modules\Organization\Domain\Models\OrganizationPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationPlan>
 */
final class OrganizationPlanFactory extends Factory
{
    protected $model = OrganizationPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'store_limit' => fake()->numberBetween(1, 10),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(fn (): array => ['store_limit' => null]);
    }

    public function withLimit(int $limit): static
    {
        return $this->state(fn (): array => ['store_limit' => $limit]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
