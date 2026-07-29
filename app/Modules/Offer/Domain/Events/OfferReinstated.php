<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A suspension was lifted (§7).
 *
 * Carries the status the offer was RESTORED to, not an assumed Active: a paused
 * offer that was then suspended goes back to paused, because reinstating is
 * undoing the admin's action — not overriding the seller's.
 *
 * @see docs/modules/Offer.md §7
 */
final class OfferReinstated extends BaseEvent
{
    public function __construct(
        public readonly int $offerId,
        public readonly string $offerUuid,
        public readonly string $variantUuid,
        public readonly string $productUuid,
        public readonly string $restoredStatus,
        public readonly ?int $reinstatedBy = null,
    ) {
        parent::__construct();
    }
}
