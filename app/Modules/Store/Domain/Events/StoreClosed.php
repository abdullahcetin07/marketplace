<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A storefront was closed by its seller (Active/Paused → Closed). Reversible by
 * reopening (activate). Distinct from an admin Suspension and from soft-delete.
 *
 * @see docs/modules/Store.md §7
 */
final class StoreClosed extends BaseEvent
{
    public function __construct(
        public readonly int $storeId,
        public readonly string $storeUuid,
        public readonly int $organizationId,
    ) {
        parent::__construct();
    }
}
