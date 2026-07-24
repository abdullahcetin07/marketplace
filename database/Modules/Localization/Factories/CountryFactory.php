<?php

declare(strict_types=1);

namespace Database\Modules\Localization\Factories;

use App\Modules\Localization\Domain\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Country>
 */
final class CountryFactory extends Factory
{
    protected $model = Country::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'iso2' => mb_strtoupper(fake()->unique()->lexify('??')),
            'iso3' => mb_strtoupper(fake()->unique()->lexify('???')),
            'numeric_code' => fake()->unique()->numerify('###'),
            'name' => fake()->country(),
            'native_name' => fake()->country(),
            'phone_code' => fake()->numerify('##'),
            'currency_id' => null,
            'timezone_id' => null,
            'flag' => '🏳️',
            'capital' => fake()->city(),
            'region' => fake()->randomElement(['Europe', 'Asia', 'Americas', 'Africa', 'Oceania']),
            'is_eu_member' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function turkiye(): static
    {
        return $this->state(fn (): array => [
            'iso2' => 'TR',
            'iso3' => 'TUR',
            'numeric_code' => '792',
            'name' => 'Türkiye',
            'native_name' => 'Türkiye',
            'phone_code' => '90',
            'flag' => '🇹🇷',
            'capital' => 'Ankara',
            'region' => 'Asia',
            'is_eu_member' => false,
            'sort_order' => 1,
        ]);
    }

    public function euMember(): static
    {
        return $this->state(fn (): array => ['is_eu_member' => true, 'region' => 'Europe']);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function forCurrency(int $currencyId): static
    {
        return $this->state(fn (): array => ['currency_id' => $currencyId]);
    }
}
