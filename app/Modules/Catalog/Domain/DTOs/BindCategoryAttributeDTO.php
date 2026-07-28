<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Binding an attribute to a category, with its PER-CATEGORY flags (§2.3).
 *
 * The flags live on the binding, not on the attribute, because Renk is a variant
 * axis in "Giyim" and a plain description in "Mobilya" — and both must still
 * filter on the same shared set of colours.
 *
 * `isVariantDefining` is a request, not a guarantee: the action refuses it for
 * an attribute whose type is not enumerable (ADR-039 — a cartesian needs finite
 * axes).
 */
final class BindCategoryAttributeDTO extends BaseDTO
{
    public function __construct(
        public readonly string $attributeUuid,
        public readonly bool $isRequired = false,
        public readonly bool $isVariantDefining = false,
        public readonly bool $isFilterable = true,
        public readonly ?int $position = null,
    ) {}
}
