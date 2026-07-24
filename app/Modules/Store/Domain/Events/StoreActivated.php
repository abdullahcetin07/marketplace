<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A storefront went live (Draft/Closed/Paused → Active).
 *
 * A downstream fact: Catalog/Offer may now surface the store's listings. Store
 * owns the event; consumers react (ADR-033) — Store calls none of them.
 *
 * @see docs/modules/Store.md §7
 */
final class StoreActivated extends BaseEvent
{
    public function __construct(
        public readonly int $storeId,
        public readonly string $storeUuid,
        public readonly int $organizationId,
    ) {
        parent::__construct();
    }
}
