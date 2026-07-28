<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A SKU was added to a product (ADR-039).
 *
 * The variant uuid is what Offer and Inventory will hold, so this is the event
 * that tells them a new sellable unit exists.
 *
 * @see docs/modules/Catalog.md §7
 */
final class ProductVariantCreated extends BaseEvent
{
    public function __construct(
        public readonly int $variantId,
        public readonly string $variantUuid,
        public readonly int $productId,
        public readonly string $productUuid,
        public readonly string $sku,
    ) {
        parent::__construct();
    }
}
