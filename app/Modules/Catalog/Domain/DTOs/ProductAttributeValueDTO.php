<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * One attribute assignment on a product (§2.4).
 *
 * TWO WAYS TO CARRY A VALUE, matching the two kinds of attribute:
 *   `valueUuid` — a `select` attribute picks one of its AttributeValue rows.
 *   `value`     — Text/Number/Boolean carry a raw value, normalised by the type.
 *
 * Both are nullable so one DTO serves both; the action refuses the wrong one for
 * the attribute's type rather than guessing, because silently accepting free
 * text for a `select` attribute is how a colour filter acquires a "kirmizi",
 * a "Kırmızı " and a "KIRMIZI".
 */
final class ProductAttributeValueDTO extends BaseDTO
{
    public function __construct(
        public readonly string $attributeUuid,
        public readonly ?string $valueUuid = null,
        public readonly ?string $value = null,
    ) {}
}
