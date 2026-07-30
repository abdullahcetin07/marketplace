<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A hold was given back — an abandoned or cancelled checkout (§6).
 *
 * The units were never gone, so nothing is "returned" in a physical sense:
 * `reserved` falls and `available` rises. A storefront that greyed something out
 * can show it again on this.
 *
 * @see docs/modules/Inventory.md §0.4, §6
 */
final class StockReleased extends BaseEvent
{
    public function __construct(
        public readonly int $stockItemId,
        public readonly string $stockItemUuid,
        public readonly string $variantUuid,
        public readonly string $sellingOrgUuid,
        public readonly int $quantity,
        public readonly string $referenceUuid,
        public readonly int $available,
    ) {
        parent::__construct();
    }
}
