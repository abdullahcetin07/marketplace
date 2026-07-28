<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A category was added to the platform taxonomy (ADR-038).
 *
 * Carries the materialised `path` so a consumer can place the node in the tree
 * without querying Catalog back (ADR-040).
 *
 * @see docs/modules/Catalog.md §7
 */
final class CategoryCreated extends BaseEvent
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $categoryUuid,
        public readonly ?int $parentId,
        public readonly string $path,
        public readonly string $slug,
    ) {
        parent::__construct();
    }
}
