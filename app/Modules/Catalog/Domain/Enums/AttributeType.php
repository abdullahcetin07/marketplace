<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * What kind of value an attribute holds (Catalog.md §2.6).
 *
 * `Select` is the only type whose values are enumerated in advance, as
 * `AttributeValue` rows (Renk → Kırmızı/Mavi). The other three carry a free
 * value supplied per product.
 *
 * THE CONSEQUENCE THAT MATTERS: only a `Select` attribute can define variants
 * (ADR-039). Variant generation is a cartesian product over chosen values, and
 * a cartesian needs a finite, enumerable axis — "Ağırlık: 2.4 kg" is a fact
 * about a product, not an axis you can multiply out. `canDefineVariants()` is
 * where that rule lives, so the binding action and the authoring UI agree.
 *
 * Extendable (§2.6): a new case is a code change by design — handling it in
 * validation and in the UI is exactly the work that makes it real, which is why
 * this is an enum and not a lookup table (CLAUDE.md "enum or lookup table").
 *
 * No `Enum` suffix (ADR-007).
 */
enum AttributeType: string
{
    use HasEnumHelpers;

    case Select = 'select';
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';

    /**
     * Whether the attribute's allowed values are enumerated as AttributeValue
     * rows rather than typed in per product.
     */
    public function usesPredefinedValues(): bool
    {
        return $this === self::Select;
    }

    /**
     * Whether an attribute of this type may be marked variant-defining on a
     * category binding (ADR-039).
     */
    public function canDefineVariants(): bool
    {
        return $this === self::Select;
    }

    /**
     * Laravel validation rules for a free value of this type. `Select` has none
     * here — its value is validated by membership of its AttributeValue set,
     * which is a database question, not a format one.
     *
     * @return array<int, string>
     */
    public function validationRules(): array
    {
        return match ($this) {
            self::Select => [],
            self::Text => ['string', 'max:255'],
            self::Number => ['numeric'],
            self::Boolean => ['boolean'],
        };
    }

    /**
     * Normalise a raw free value to the canonical string stored on the pivot.
     *
     * Values are persisted as text so one column serves every type; this is the
     * single place that decides what "true" and "1.50" look like on the way in,
     * so two products never disagree about the same fact.
     */
    public function normalise(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this) {
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            self::Number => is_numeric($value) ? (string) (0 + $value) : null,
            default => is_scalar($value) ? trim((string) $value) : null,
        };
    }
}
