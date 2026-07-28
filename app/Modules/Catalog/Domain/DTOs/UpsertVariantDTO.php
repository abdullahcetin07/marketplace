<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Creating or editing one SKU (§2.5, ADR-039).
 *
 * `valueUuids` is the variant's axes — one AttributeValue per variant-defining
 * attribute of the product's category. An empty list is legitimate and means the
 * single default variant of a product with no axes; it is not a missing value.
 *
 * `sku` is optional: the action generates one when it is absent, so authoring a
 * 12-variant product does not mean inventing 12 codes by hand.
 *
 * NO PRICE AND NO STOCK (ADR-037). A variant says what the thing IS.
 */
final class UpsertVariantDTO extends BaseDTO
{
    /**
     * @param array<int, string> $valueUuids
     */
    public function __construct(
        public readonly array $valueUuids = [],
        public readonly ?string $variantUuid = null,
        public readonly ?string $sku = null,
        public readonly ?string $barcode = null,
        public readonly ?int $position = null,
        public readonly bool $isDefault = false,
    ) {}
}
