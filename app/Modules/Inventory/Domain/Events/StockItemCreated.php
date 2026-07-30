<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A seller's stock pool for a variant now exists (§6).
 *
 * Raised the first time an Offer's stock is mirrored — a pool comes into being
 * because somebody listed something, never on its own. Carries uuids only, so a
 * listener in another context can act without importing Inventory.
 *
 * @see docs/modules/Inventory.md §6
 */
final class StockItemCreated extends BaseEvent
{
    public function __construct(
        public readonly int $stockItemId,
        public readonly string $stockItemUuid,
        public readonly string $variantUuid,
        public readonly string $productUuid,
        public readonly string $sellingOrgUuid,
        public readonly int $onHand,
    ) {
        parent::__construct();
    }
}
