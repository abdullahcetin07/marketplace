<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Adding a brand (§2.2).
 *
 * Not localized: a brand name is a proper noun, the same in every locale.
 */
final class CreateBrandDTO extends BaseDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $slug = null,
        public readonly bool $isActive = true,
    ) {}
}
