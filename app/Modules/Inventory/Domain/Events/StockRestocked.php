<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A sale was undone: the units came back (Payment.md §8, P5).
 *
 * THE MIRROR OF `StockCommitted`, AND ITS OPPOSITE IN ONE RESPECT. A commit
 * lowers both numbers; this raises `on_hand` alone, because the hold ended when
 * the sale completed and a refund does not re-hold anything — the units are
 * simply back on the shelf, unheld and sellable.
 *
 * IT IS NOT `StockReleased`. That one says a hold was given back without the
 * units ever leaving; this says they left and returned. Anyone reconciling stock
 * needs to tell those apart, which is the whole reason this is a separate event
 * with a separate movement type rather than a reused one.
 *
 * @see docs/modules/Inventory.md §0.4, §6
 * @see docs/modules/Payment.md §8
 */
final class StockRestocked extends BaseEvent
{
    public function __construct(
        public readonly int $stockItemId,
        public readonly string $stockItemUuid,
        public readonly string $variantUuid,
        public readonly string $sellingOrgUuid,
        public readonly int $quantity,
        public readonly string $reference,
        public readonly int $onHand,
        public readonly int $available,
    ) {
        parent::__construct();
    }
}
