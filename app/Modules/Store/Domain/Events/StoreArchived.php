<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A storefront was retired (Closed/Suspended → Archived) — a read-only business
 * end-state, distinct from the recoverable removal `deleted_at` represents.
 *
 * @see docs/modules/Store.md §7
 */
final class StoreArchived extends BaseEvent
{
    public function __construct(
        public readonly int $storeId,
        public readonly string $storeUuid,
        public readonly int $organizationId,
    ) {
        parent::__construct();
    }
}
