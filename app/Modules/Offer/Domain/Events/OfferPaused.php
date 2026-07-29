<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An offer left the buy box without being destroyed (§7).
 *
 * TWO CAUSES, ONE EVENT, DISTINGUISHED BY A FLAG. The seller pauses it
 * deliberately, or Catalog archiving the product cascades it (§3.5). Consumers
 * that only care "this stopped selling" need neither branch; the ones that
 * report to the seller need to know which, because "you paused this" and "the
 * platform de-listed the product" are very different messages.
 *
 * @see docs/modules/Offer.md §3.5, §7
 */
final class OfferPaused extends BaseEvent
{
    public function __construct(
        public readonly int $offerId,
        public readonly string $offerUuid,
        public readonly string $variantUuid,
        public readonly string $productUuid,
        /** True when Catalog's product lifecycle caused it, not the seller. */
        public readonly bool $byCascade = false,
    ) {
        parent::__construct();
    }
}
