<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Adding one allowed value to a `select` attribute (§2.3).
 *
 * `value` is the machine handle (`red`), `label` the localized text a seller
 * picks from — the same split as the attribute's code/name, for the same reason.
 */
final class CreateAttributeValueDTO extends BaseDTO
{
    /**
     * @param array<string, string|null> $label
     */
    public function __construct(
        public readonly string $attributeUuid,
        public readonly string $value,
        public readonly array $label,
        public readonly bool $isActive = true,
        public readonly ?int $position = null,
    ) {}
}
