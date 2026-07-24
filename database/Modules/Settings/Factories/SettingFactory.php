<?php

declare(strict_types=1);

namespace Database\Modules\Settings\Factories;

use App\Modules\Settings\Domain\Enums\SettingGroup;
use App\Modules\Settings\Domain\Enums\SettingType;
use App\Modules\Settings\Domain\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Setting>
 */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'key' => 'general.'.fake()->unique()->slug(2, false),
            'group' => SettingGroup::General,
            'type' => SettingType::String,
            'value' => null,
            'default_value' => fake()->word(),
            'label' => fake()->words(2, true),
            'description' => null,
            'is_public' => false,
            'is_encrypted' => false,
            'is_locked' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Typed states. Each sets `type` AND a correctly serialised default, so a
     * fixture can never end up with a boolean setting whose default is the
     * string "yes".
     */
    public function boolean(bool $default = false): static
    {
        return $this->state(fn (): array => [
            'type' => SettingType::Boolean,
            'default_value' => SettingType::Boolean->serialise($default),
        ]);
    }

    public function integer(int $default = 0): static
    {
        return $this->state(fn (): array => [
            'type' => SettingType::Integer,
            'default_value' => SettingType::Integer->serialise($default),
        ]);
    }

    /**
     * @param  array<mixed>  $default
     */
    public function json(array $default = []): static
    {
        return $this->state(fn (): array => [
            'type' => SettingType::Json,
            'default_value' => SettingType::Json->serialise($default),
        ]);
    }

    public function inGroup(SettingGroup $group): static
    {
        return $this->state(fn (): array => [
            'group' => $group,
            'key' => $group->value.'.'.fake()->unique()->slug(2, false),
        ]);
    }

    public function publiclyReadable(): static
    {
        return $this->state(fn (): array => [
            'group' => SettingGroup::General,
            'is_public' => true,
            'is_encrypted' => false,
        ]);
    }

    public function encrypted(): static
    {
        return $this->state(fn (): array => [
            'is_encrypted' => true,
            // An encrypted setting is never public, whatever the flag says —
            // Setting::isPubliclyReadable() enforces it, and the fixture
            // should not pretend otherwise.
            'is_public' => false,
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (): array => ['is_locked' => true]);
    }
}
