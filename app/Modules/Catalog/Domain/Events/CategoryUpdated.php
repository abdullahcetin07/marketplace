<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A category's own fields changed — a rename, a re-parent, a reorder.
 *
 * `changed` lists the attribute names that actually moved, so a consumer that
 * only cares about re-parenting need not diff the node itself. A move is the
 * expensive case (it rewrites the subtree's paths, §13.1), which is why `path`
 * travels on the event.
 *
 * @see docs/modules/Catalog.md §7
 */
final class CategoryUpdated extends BaseEvent
{
    /**
     * @param array<int, string> $changed
     */
    public function __construct(
        public readonly int $categoryId,
        public readonly string $categoryUuid,
        public readonly string $path,
        public readonly array $changed = [],
    ) {
        parent::__construct();
    }
}
