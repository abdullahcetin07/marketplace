<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * Units are being held for an in-flight checkout (§6, ADR-049).
 *
 * ON-HAND HAS NOT MOVED. Nothing physical left the seller — the units are
 * spoken for, not sold — which is exactly the distinction a bare counter cannot
 * make and this module exists to record. `available` is what changed.
 *
 * Carries the caller's reference so a listener can correlate the hold with
 * whatever created it, without Inventory knowing what that was.
 *
 * @see docs/modules/Inventory.md §0.4, §6
 */
final class StockReserved extends BaseEvent
{
    public function __construct(
        public readonly int $stockItemId,
        public readonly string $stockItemUuid,
        public readonly string $variantUuid,
        public readonly string $sellingOrgUuid,
        public readonly int $quantity,
        public readonly string $reference,
        public readonly int $available,
    ) {
        parent::__construct();
    }
}
