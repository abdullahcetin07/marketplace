<?php

declare(strict_types=1);

namespace Database\Modules\Catalog\Factories;

use App\Modules\Catalog\Domain\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TaxRate>
 */
final class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    /**
     * Defaults to the GENERAL bracket, %20, because that is what an arbitrary
     * product is: the specific brackets exist for named exceptions (food, books,
     * exempt goods) and a test wanting one says so.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            // Unique because the column is: a test creating two brackets without
            // saying which must not collide on the seeder's key.
            'code' => 'kdv-'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'KDV %20',
            'rate' => '0.2000',
            'is_active' => true,
        ];
    }

    /**
     * A bracket at an exact ratio — the readable way for a tax test to say "this
     * one is %10".
     *
     * Takes the ratio as a STRING so the test reads like the column and no float
     * literal ever enters the fixture.
     */
    public function rate(string $ratio, ?string $name = null): static
    {
        return $this->state(fn (): array => [
            'rate' => $ratio,
            'name' => $name ?? 'KDV '.$ratio,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
