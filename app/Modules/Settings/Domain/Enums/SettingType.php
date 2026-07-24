<?php

declare(strict_types=1);

namespace App\Modules\Settings\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * How a setting's stored string is interpreted.
 *
 * WHY A TYPE COLUMN AT ALL: settings are stored in one text column, because a
 * column per type would mean a schema change every time a new kind of setting
 * appears. The cost of that is that `false`, `0` and `"0"` are indistinguishable
 * on the way out — so the type is stored alongside and applied on read.
 * Without it, a boolean setting set to false comes back as the string "0",
 * which is truthy in some comparisons and falsy in others.
 */
enum SettingType: string
{
    use HasEnumHelpers;

    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Json = 'json';
    case Text = 'text';

    /**
     * Storage -> PHP.
     */
    public function cast(?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($this) {
            self::String, self::Text => $raw,
            self::Integer => (int) $raw,
            // Accept every spelling an admin form or seeder might produce.
            // filter_var returns null (not false) for unrecognised input, which
            // is why the ?? is here rather than a bare cast.
            self::Boolean => filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            self::Json => json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
        };
    }

    /**
     * PHP -> storage.
     */
    public function serialise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String, self::Text => (string) $value,
            self::Integer => (string) (int) $value,
            // '1'/'0' rather than 'true'/'false': unambiguous, and round-trips
            // through FILTER_VALIDATE_BOOL correctly.
            self::Boolean => $value ? '1' : '0',
            self::Json => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        };
    }

    /**
     * Validation rules for an admin form editing a setting of this type.
     *
     * @return array<int, string>
     */
    public function validationRules(): array
    {
        return match ($this) {
            self::String => ['nullable', 'string', 'max:255'],
            self::Text => ['nullable', 'string', 'max:65535'],
            self::Integer => ['nullable', 'integer'],
            self::Boolean => ['nullable', 'boolean'],
            self::Json => ['nullable', 'json'],
        };
    }

    /**
     * Filament form component for the admin UI.
     */
    public function formComponent(): string
    {
        return match ($this) {
            self::String => 'TextInput',
            self::Text, self::Json => 'Textarea',
            self::Integer => 'TextInput',
            self::Boolean => 'Toggle',
        };
    }
}
