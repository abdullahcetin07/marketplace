<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A hold became a sale: the units left (§6, ADR-049).
 *
 * THE ONLY EVENT WHERE ON-HAND FALLS for a reason other than the seller editing
 * it — and this sprint nothing raises it in production, because Order does not
 * exist. That is the point of building the authority first: the primitive and
 * its event are here for Order to call, tested, rather than designed in a hurry
 * alongside a checkout.
 *
 * Both numbers move together, which is why the payload carries both.
 *
 * @see docs/modules/Inventory.md §0.4, §6
 */
final class StockCommitted extends BaseEvent
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
