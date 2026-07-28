<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A new attribute was defined for the taxonomy (ADR-038).
 *
 * `code` rather than the label, because the code is the stable handle a search
 * facet or an importer keys on; labels are localized and may be re-worded.
 *
 * @see docs/modules/Catalog.md §7
 */
final class AttributeCreated extends BaseEvent
{
    public function __construct(
        public readonly int $attributeId,
        public readonly string $attributeUuid,
        public readonly string $code,
        public readonly string $type,
    ) {
        parent::__construct();
    }
}
