<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A brand was added to the platform.
 *
 * @see docs/modules/Catalog.md §7
 */
final class BrandCreated extends BaseEvent
{
    public function __construct(
        public readonly int $brandId,
        public readonly string $brandUuid,
        public readonly string $name,
        public readonly string $slug,
    ) {
        parent::__construct();
    }
}
