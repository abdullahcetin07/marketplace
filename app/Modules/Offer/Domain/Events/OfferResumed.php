<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A paused offer is live again (§7).
 *
 * `byCascade` mirrors OfferPaused: a re-published product reactivates exactly
 * the offers its archiving paused, and nothing else — a seller who paused an
 * offer for their own reasons keeps it paused through a product's round trip.
 *
 * @see docs/modules/Offer.md §3.5, §7
 */
final class OfferResumed extends BaseEvent
{
    public function __construct(
        public readonly int $offerId,
        public readonly string $offerUuid,
        public readonly string $variantUuid,
        public readonly string $productUuid,
        public readonly bool $byCascade = false,
    ) {
        parent::__construct();
    }
}
