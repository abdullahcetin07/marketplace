<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A SKU's own fields changed — its code, its barcode, its ordering.
 *
 * @see docs/modules/Catalog.md §7
 */
final class ProductVariantUpdated extends BaseEvent
{
    /**
     * @param array<int, string> $changed
     */
    public function __construct(
        public readonly int $variantId,
        public readonly string $variantUuid,
        public readonly int $productId,
        public readonly string $sku,
        public readonly array $changed = [],
    ) {
        parent::__construct();
    }
}
