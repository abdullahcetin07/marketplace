<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * The seller removed their offer for good (§7).
 *
 * Terminal from the seller's side and soft-deleted, because a future order line
 * will reference the offer it was bought from — the row outlives the listing.
 * Search must drop it immediately; it never appears on a product page again.
 *
 * @see docs/modules/Offer.md §7
 */
final class OfferWithdrawn extends BaseEvent
{
    public function __construct(
        public readonly int $offerId,
        public readonly string $offerUuid,
        public readonly string $variantUuid,
        public readonly string $productUuid,
        public readonly string $sellingOrgUuid,
    ) {
        parent::__construct();
    }
}
