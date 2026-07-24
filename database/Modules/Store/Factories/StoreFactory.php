<?php

declare(strict_types=1);

namespace Database\Modules\Store\Factories;

use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
final class StoreFactory extends Factory
{
    protected $model = Store::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $organization = Organization::factory();

        return [
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization,
            // A freshly-generated org uuid keeps the denormalised copy consistent
            // in tests that do not inspect the real one.
            'organization_uuid' => (string) Str::uuid(),
            'opening_request_uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'store_number' => 'ST-'.Str::upper(Str::random(8)),
            'status' => StoreStatus::Draft,
            'default_language_id' => Language::query()->value('id') ?? Language::factory(),
            'default_currency_id' => Currency::query()->value('id') ?? Currency::factory(),
            'timezone_id' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => StoreStatus::Active,
            'activated_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => StoreStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
