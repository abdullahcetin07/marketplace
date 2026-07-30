<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * Availability fell to the seller's warning line (§3.3).
 *
 * EDGE-TRIGGERED, NOT LEVEL-TRIGGERED. It fires on the movement that CROSSES the
 * threshold downward and then re-arms only when availability climbs back above
 * it. Firing on every movement while stock stayed low would train the seller to
 * ignore the one notification that matters — which is the same failure mode as
 * not sending it at all.
 *
 * The threshold travels with the event so a notification can say "3 left, you
 * asked to hear at 5" without reading Inventory back.
 *
 * @see docs/modules/Inventory.md §3.3, §6
 */
final class StockLowStockReached extends BaseEvent
{
    public function __construct(
        public readonly int $stockItemId,
        public readonly string $stockItemUuid,
        public readonly string $variantUuid,
        public readonly string $productUuid,
        public readonly string $sellingOrgUuid,
        public readonly int $available,
        public readonly int $threshold,
    ) {
        parent::__construct();
    }
}
