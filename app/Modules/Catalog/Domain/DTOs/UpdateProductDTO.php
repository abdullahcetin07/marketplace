<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Editing a product's own fields.
 *
 * PATCH-style via `present`, so an edit form that omits `gtin` leaves it alone
 * instead of clearing the catalog's dedup key.
 *
 * `status` is absent deliberately: moderation state moves only through the
 * lifecycle actions (§3.1), never as a field on a content edit. `slug` is
 * present but stable by policy (§3.5) — changing it re-points a public URL, so
 * the action re-checks global uniqueness.
 */
final class UpdateProductDTO extends BaseDTO
{
    /**
     * @param array<string, string|null> $title
     * @param array<string, string|null> $description
     * @param array<int, string> $present
     */
    public function __construct(
        public readonly array $title = [],
        public readonly array $description = [],
        public readonly ?string $categoryUuid = null,
        public readonly ?string $brandUuid = null,
        public readonly ?string $gtin = null,
        public readonly ?string $slug = null,
        public readonly array $present = [],
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
