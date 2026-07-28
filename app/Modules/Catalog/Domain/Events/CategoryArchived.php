<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A category was deactivated and withdrawn from the taxonomy.
 *
 * Archiving a category is `is_active = false` (ADR-015), never a delete: the
 * products attached to it keep pointing somewhere real, and reactivating is one
 * flag rather than a restore.
 *
 * @see docs/modules/Catalog.md §7
 */
final class CategoryArchived extends BaseEvent
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $categoryUuid,
        public readonly string $path,
    ) {
        parent::__construct();
    }
}
