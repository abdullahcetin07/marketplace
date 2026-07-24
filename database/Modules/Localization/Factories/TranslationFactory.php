<?php

declare(strict_types=1);

namespace Database\Modules\Localization\Factories;

use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Localization\Domain\Models\Translation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Translation>
 */
final class TranslationFactory extends Factory
{
    protected $model = Translation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'language_id' => Language::factory(),
            'group' => 'errors',
            'key' => fake()->unique()->slug(2, false),
            'value' => fake()->sentence(),
            'is_overridden' => false,
        ];
    }

    public function forLanguage(Language|int $language): static
    {
        return $this->state(fn (): array => [
            'language_id' => $language instanceof Language ? $language->getKey() : $language,
        ]);
    }

    public function inGroup(string $group): static
    {
        return $this->state(fn (): array => ['group' => $group]);
    }

    /**
     * A JSON-style translation (`__('Some string')`), which has no lang file
     * group of its own.
     */
    public function json(): static
    {
        return $this->state(fn (): array => ['group' => Translation::JSON_GROUP]);
    }

    /**
     * An override of a string that ships in a lang file, as opposed to a new
     * key. Drives the "modified from default" indicator in the admin UI.
     */
    public function overriding(string $group, string $key, string $value): static
    {
        return $this->state(fn (): array => [
            'group' => $group,
            'key' => $key,
            'value' => $value,
            'is_overridden' => true,
        ]);
    }
}
