<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A seller editing a storefront's theme (§2.3). PATCH via `present`.
 *
 * Only the theme fields — the logo/banner/favicon media are uploaded through
 * the media endpoints, not this DTO.
 */
final class UpdateStoreBrandingDTO extends BaseDTO
{
    /**
     * @param  array<int, string>  $present
     */
    public function __construct(
        public readonly ?string $primaryColor = null,
        public readonly ?string $accentColor = null,
        public readonly ?string $theme = null,
        public readonly array $present = [],
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
