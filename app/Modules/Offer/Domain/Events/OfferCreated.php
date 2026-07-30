<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A seller listed a catalog variant at a price (§7).
 *
 * THE MOMENT A PRODUCT BECOMES BUYABLE. Search subscribes to it because a
 * product is searchable-to-buy only once it carries an active in-stock offer
 * (§10) — before this event the catalog entry exists but nothing sells it.
 *
 * Carries uuids, never models: a listener in another context must be able to act
 * on this without importing Offer.
 *
 * @see docs/modules/Offer.md §7
 */
final class OfferCreated extends BaseEvent
{
    public function __construct(
        public readonly int $offerId,
        public readonly string $offerUuid,
        public readonly string $variantUuid,
        public readonly string $productUuid,
        // Added for Inventory: the ADR-040 pair, because a consumer that may
        // not import Offer cannot resolve one from the other.
        public readonly int $sellingOrgId,
        public readonly string $sellingOrgUuid,
        public readonly string $storeUuid,
        public readonly int $priceMinor,
        public readonly int $stockQuantity,
    ) {
        parent::__construct();
    }
}
