<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A seller set a new stock quantity (§7).
 *
 * Distinct from a price change because it changes a different fact: crossing
 * zero in either direction moves the offer in or out of the buy box without the
 * price moving at all. `becameOutOfStock` / `becameInStock` are computed here
 * rather than left for each listener to re-derive from the two integers.
 *
 * @see docs/modules/Offer.md §7
 */
final class OfferStockChanged extends BaseEvent
{
    public function __construct(
        public readonly int $offerId,
        public readonly string $offerUuid,
        public readonly string $variantUuid,
        public readonly string $productUuid,
        public readonly int $previousStockQuantity,
        public readonly int $stockQuantity,
    ) {
        parent::__construct();
    }

    public function becameOutOfStock(): bool
    {
        return $this->previousStockQuantity > 0 && $this->stockQuantity === 0;
    }

    public function becameInStock(): bool
    {
        return $this->previousStockQuantity === 0 && $this->stockQuantity > 0;
    }
}
