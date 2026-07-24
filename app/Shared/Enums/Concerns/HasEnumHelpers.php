<?php

declare(strict_types=1);

namespace App\Shared\Enums\Concerns;

use BackedEnum;

/**
 * Shared behaviour for every backed enum in the platform.
 *
 * Enums are the single source of truth for closed value sets. They are never
 * mirrored into lookup tables — see docs/001_Architecture.md §"Enums over lookup
 * tables" for the reasoning.
 *
 * @mixin BackedEnum
 */
trait HasEnumHelpers
{
    /**
     * Every case value, in declaration order.
     *
     * @return array<int, string|int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Every case name, in declaration order.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * value => translated label, ready for a <select> or a Filament ->options().
     *
     * @return array<string|int, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Resolve a case from a raw value, falling back instead of throwing.
     *
     * Use this at trust boundaries (query strings, imported CSV, webhooks)
     * where a bad value must not become a 500.
     */
    public static function tryFromValue(mixed $value, ?self $default = null): ?static
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return $default;
        }

        return self::tryFrom($value) ?? $default;
    }

    /**
     * Human-readable label, resolved through the translator so the admin panel
     * renders Turkish and the API can render English.
     *
     * Translation keys live in lang/{locale}/enums.php under the enum's short
     * class name, e.g. `enums.Status.active`.
     */
    public function label(): string
    {
        $key = 'enums.'.class_basename(static::class).'.'.$this->value;

        $translated = __($key);

        // __() returns the key itself when no translation exists; degrade to a
        // readable form rather than leaking the dotted key into the UI.
        return is_string($translated) && $translated !== $key
            ? $translated
            : str(is_string($this->value) ? $this->value : (string) $this->value)
                ->replace(['_', '-'], ' ')
                ->title()
                ->value();
    }

    /**
     * Compare against one or more cases or raw values.
     */
    public function is(self|string|int ...$candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate instanceof self && $candidate === $this) {
                return true;
            }

            if (! $candidate instanceof self && $candidate === $this->value) {
                return true;
            }
        }

        return false;
    }

    public function isNot(self|string|int ...$candidates): bool
    {
        return ! $this->is(...$candidates);
    }
}
