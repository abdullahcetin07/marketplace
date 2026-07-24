<?php

declare(strict_types=1);

namespace Database\Modules\Organization\Factories;

use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationKyc;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationKyc>
 */
final class OrganizationKycFactory extends Factory
{
    protected $model = OrganizationKyc::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'organization_id' => Organization::factory(),
            'tax_number' => (string) fake()->numerify('##########'),
            'registration_number' => (string) fake()->numerify('######'),
            'authorized_person_name' => fake()->name(),
            'authorized_person_national_id' => (string) fake()->numerify('###########'),
            'metadata' => null,
            'submitted_at' => now(),
        ];
    }

    /**
     * Turkey-specific extension fields in the metadata bag.
     */
    public function turkish(): static
    {
        return $this->state(fn (): array => [
            'metadata' => [
                'mersis' => (string) fake()->numerify('################'),
                'tax_office' => fake()->city().' VD',
                'trade_registry' => (string) fake()->numerify('######'),
            ],
        ]);
    }
}
