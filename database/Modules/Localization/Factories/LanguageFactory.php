<?php

declare(strict_types=1);

namespace Database\Modules\Localization\Factories;

use App\Modules\Localization\Domain\Enums\TextDirection;
use App\Modules\Localization\Domain\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Language>
 */
final class LanguageFactory extends Factory
{
    protected $model = Language::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->lexify('??');

        return [
            'uuid' => (string) Str::uuid(),
            'code' => $code,
            'locale' => $code.'-'.mb_strtoupper($code),
            'name' => fake()->word(),
            'native_name' => fake()->word(),
            'direction' => TextDirection::Ltr,
            'flag' => '🏳️',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function turkish(): static
    {
        return $this->state(fn (): array => [
            'code' => 'tr',
            'locale' => 'tr-TR',
            'name' => 'Turkish',
            'native_name' => 'Türkçe',
            'direction' => TextDirection::Ltr,
            'flag' => '🇹🇷',
            'sort_order' => 1,
        ]);
    }

    public function english(): static
    {
        return $this->state(fn (): array => [
            'code' => 'en',
            'locale' => 'en-GB',
            'name' => 'English',
            'native_name' => 'English',
            'direction' => TextDirection::Ltr,
            'flag' => '🇬🇧',
            'sort_order' => 2,
        ]);
    }

    /**
     * An RTL language. RTL support is structural rather than a retrofit, so
     * there must be a fixture that exercises it.
     */
    public function arabic(): static
    {
        return $this->state(fn (): array => [
            'code' => 'ar',
            'locale' => 'ar-SA',
            'name' => 'Arabic',
            'native_name' => 'العربية',
            'direction' => TextDirection::Rtl,
            'flag' => '🇸🇦',
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
}
