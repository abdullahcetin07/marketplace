<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A seller changed how much they have (§6).
 *
 * The mirror of the Offer's stock edit, after Inventory has recorded it. Carries
 * BOTH sides and the resulting availability, because the interesting question
 * downstream is rarely the new number by itself — search wants to know whether
 * something became buyable, and a report wants to know what moved.
 *
 * @see docs/modules/Inventory.md §3.1, §6
 */
final class StockAdjusted extends BaseEvent
{
    public function __construct(
        public readonly int $stockItemId,
        public readonly string $stockItemUuid,
        public readonly string $variantUuid,
        public readonly string $sellingOrgUuid,
        public readonly int $previousOnHand,
        public readonly int $onHand,
        public readonly int $available,
    ) {
        parent::__construct();
    }
}
