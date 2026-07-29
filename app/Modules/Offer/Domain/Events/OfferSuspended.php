<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An admin pulled a single offer (§7, ADR-044).
 *
 * THE ONLY OVERSIGHT LEVER THIS MODULE HAS. Offers are not moderated before they
 * go live, so an abusive price is caught reactively — and this event is the
 * record of that catch. It carries the reason and the acting admin because
 * suspension is a decision someone has to be able to answer for later.
 *
 * @see docs/modules/Offer.md §7
 */
final class OfferSuspended extends BaseEvent
{
    public function __construct(
        public readonly int $offerId,
        public readonly string $offerUuid,
        public readonly string $variantUuid,
        public readonly string $productUuid,
        public readonly string $sellingOrgUuid,
        public readonly ?int $suspendedBy = null,
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }
}
