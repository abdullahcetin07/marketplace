<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A seller editing a storefront's SEO metadata (§2.4). PATCH via `present`.
 */
final class UpdateStoreSeoDTO extends BaseDTO
{
    /**
     * @param  array<int, string>|null  $metaKeywords
     * @param  array<int, string>  $present
     */
    public function __construct(
        public readonly ?string $metaTitle = null,
        public readonly ?string $metaDescription = null,
        public readonly ?array $metaKeywords = null,
        public readonly ?string $canonicalUrl = null,
        public readonly ?string $robots = null,
        public readonly array $present = [],
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
