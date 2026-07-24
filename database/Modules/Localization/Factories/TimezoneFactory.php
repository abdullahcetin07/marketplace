<?php

declare(strict_types=1);

namespace Database\Modules\Localization\Factories;

use App\Modules\Localization\Domain\Models\Timezone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Timezone>
 */
final class TimezoneFactory extends Factory
{
    protected $model = Timezone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Drawn from real IANA identifiers — Timezone::toDateTimeZone()
        // constructs a DateTimeZone from this and would throw on a fake value.
        $name = fake()->unique()->randomElement([
            'Europe/Istanbul', 'UTC', 'Europe/London', 'Europe/Berlin',
            'Europe/Amsterdam', 'Europe/Paris', 'America/New_York',
            'America/Chicago', 'Asia/Tokyo', 'Australia/Sydney',
        ]);

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'label' => Str::afterLast($name, '/'),
            'offset_minutes' => 0,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function istanbul(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Europe/Istanbul',
            'label' => 'İstanbul',
            'offset_minutes' => 180,
            'sort_order' => 1,
        ]);
    }

    public function utc(): static
    {
        return $this->state(fn (): array => [
            'name' => 'UTC',
            'label' => 'UTC',
            'offset_minutes' => 0,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
