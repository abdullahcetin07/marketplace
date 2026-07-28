<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A Category Manager editing a taxonomy node.
 *
 * PATCH-style via `present`: a null `slug` means "not supplied", not "clear it".
 * Without that distinction an edit form that omits a field would silently blank
 * it — the failure mode `UpdateStoreDTO` already guards against.
 *
 * Re-parenting is included here, but it is the expensive edit: it rewrites the
 * materialised paths of the whole subtree (§13.1). The action, not the DTO,
 * refuses a move that would make a node its own ancestor.
 */
final class UpdateCategoryDTO extends BaseDTO
{
    /**
     * @param array<string, string|null> $name
     * @param array<int, string> $present
     */
    public function __construct(
        public readonly array $name = [],
        public readonly ?string $parentUuid = null,
        public readonly ?string $slug = null,
        public readonly ?bool $isActive = null,
        public readonly ?int $position = null,
        public readonly array $present = [],
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
