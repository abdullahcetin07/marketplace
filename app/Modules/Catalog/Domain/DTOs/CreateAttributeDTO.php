<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Modules\Catalog\Domain\Enums\AttributeType;

/**
 * Defining a new attribute (§2.3).
 *
 * `code` is the stable machine handle (`color`); `name` is the localized label
 * humans read. They are separate because re-wording a label must not invalidate
 * a search facet or an import mapping.
 */
final class CreateAttributeDTO extends BaseDTO
{
    /**
     * @param array<string, string|null> $name
     */
    public function __construct(
        public readonly string $code,
        public readonly array $name,
        public readonly AttributeType $type = AttributeType::Select,
        public readonly bool $isVariantDefining = false,
        public readonly bool $isFilterable = true,
        public readonly bool $isActive = true,
        public readonly ?int $position = null,
    ) {}
}
