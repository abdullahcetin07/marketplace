<?php

declare(strict_types=1);

namespace Database\Modules\Organization\Factories;

use App\Models\Seller;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'uuid' => (string) Str::uuid(),
            // The owner is a Seller — the only actor type that may own a company.
            'owner_id' => Seller::factory(),
            'legal_name' => $name,
            'display_name' => null,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'status' => OrganizationStatus::Pending,
            'plan_id' => null,
            'store_limit_override' => null,
            // Reuse seeded Localization rows when present; create otherwise.
            'country_id' => Country::query()->value('id') ?? Country::factory(),
            'currency_id' => Currency::query()->value('id') ?? Currency::factory(),
            'verified_at' => null,
            'suspended_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizationStatus::Approved,
            'verified_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }

    public function withPlan(OrganizationPlan $plan): static
    {
        return $this->state(fn (): array => ['plan_id' => $plan->getKey()]);
    }

    public function withStoreLimitOverride(int $limit): static
    {
        return $this->state(fn (): array => ['store_limit_override' => $limit]);
    }
}
