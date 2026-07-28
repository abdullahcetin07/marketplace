<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An attribute's definition changed — a re-label, a filterability flag, a new
 * allowed value.
 *
 * @see docs/modules/Catalog.md §7
 */
final class AttributeUpdated extends BaseEvent
{
    /**
     * @param array<int, string> $changed
     */
    public function __construct(
        public readonly int $attributeId,
        public readonly string $attributeUuid,
        public readonly string $code,
        public readonly array $changed = [],
    ) {
        parent::__construct();
    }
}
