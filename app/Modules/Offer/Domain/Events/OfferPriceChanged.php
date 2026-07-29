<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A seller re-priced their offer (§7).
 *
 * CARRIES BOTH SIDES on purpose. The buy box is computed, not stored (ADR-045),
 * so nothing needs invalidating — but a price-history, a price-drop alert or a
 * competitiveness report all need to know what it WAS, and reconstructing that
 * from the audit trail would make every consumer depend on Audit's schema.
 *
 * @see docs/modules/Offer.md §7
 */
final class OfferPriceChanged extends BaseEvent
{
    public function __construct(
        public readonly int $offerId,
        public readonly string $offerUuid,
        public readonly string $variantUuid,
        public readonly string $productUuid,
        public readonly int $previousPriceMinor,
        public readonly int $priceMinor,
        public readonly ?int $previousListPriceMinor = null,
        public readonly ?int $listPriceMinor = null,
    ) {
        parent::__construct();
    }
}
