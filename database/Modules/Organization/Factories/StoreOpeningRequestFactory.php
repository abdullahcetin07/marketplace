<?php

declare(strict_types=1);

namespace Database\Modules\Organization\Factories;

use App\Models\Seller;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoreOpeningRequest>
 */
final class StoreOpeningRequestFactory extends Factory
{
    protected $model = StoreOpeningRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'uuid' => (string) Str::uuid(),
            'organization_id' => Organization::factory(),
            'requested_by' => Seller::factory(),
            'status' => StoreOpeningRequestStatus::Draft,
            'store_name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'category_id' => null,
            'description' => fake()->sentence(),
            'reason' => fake()->sentence(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => StoreOpeningRequestStatus::Pending,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => StoreOpeningRequestStatus::Approved,
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);
    }
}
