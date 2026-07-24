<?php

declare(strict_types=1);

namespace Database\Modules\Localization\Factories;

use App\Modules\Localization\Domain\Enums\SymbolPosition;
use App\Modules\Localization\Domain\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Currency>
 */
final class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            // unique() so a test creating several currencies does not trip the
            // unique index on `code`.
            'code' => mb_strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->word(),
            'native_name' => fake()->word(),
            'symbol' => fake()->randomElement(['$', '€', '£', '₺', '¥']),
            'symbol_position' => SymbolPosition::After,
            'decimal_places' => 2,
            'decimal_separator' => ',',
            'thousands_separator' => '.',
            'exchange_rate' => '1.0000000000',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * The Turkish Lira, exactly as the installer seeds it. Use this rather than
     * a random currency whenever a test asserts on formatting.
     */
    public function turkishLira(): static
    {
        return $this->state(fn (): array => [
            'code' => 'TRY',
            'name' => 'Turkish Lira',
            'native_name' => 'Türk Lirası',
            'symbol' => '₺',
            'symbol_position' => SymbolPosition::After,
            'decimal_separator' => ',',
            'thousands_separator' => '.',
            'exchange_rate' => '1.0000000000',
            'sort_order' => 1,
        ]);
    }

    public function usDollar(): static
    {
        return $this->state(fn (): array => [
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'symbol_position' => SymbolPosition::Before,
            'decimal_separator' => '.',
            'thousands_separator' => ',',
            'exchange_rate' => '0.0290000000',
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true, 'is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * A zero-decimal currency. Exists so tests can prove nothing assumes 2.
     */
    public function zeroDecimal(): static
    {
        return $this->state(fn (): array => [
            'code' => 'JPY',
            'symbol' => '¥',
            'decimal_places' => 0,
        ]);
    }

    public function withStaleRate(): static
    {
        return $this->state(fn (): array => [
            'is_default' => false,
            'rate_updated_at' => now()->subDays(3),
        ]);
    }
}
