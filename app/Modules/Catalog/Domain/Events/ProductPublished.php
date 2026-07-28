<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A product was approved and is live in the shared catalog (§3.1).
 *
 * THE MODULE'S MOST CONSUMED EVENT. Search indexes on it (§10), and from the
 * Offer sprint this is when sellers may start attaching offers to the product's
 * variants. Approving creates nothing new — the product simply moved (§5) —
 * which is why there is a `ProductPublished` and no `ProductCreated`.
 *
 * @see docs/modules/Catalog.md §7, §10
 */
final class ProductPublished extends BaseEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productUuid,
        public readonly int $categoryId,
        public readonly ?string $proposedByOrgUuid,
        public readonly ?int $moderatedBy = null,
    ) {
        parent::__construct();
    }
}
