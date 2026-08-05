<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * The parcel is with the carrier (ADR-063, Shipping.md §2).
 *
 * IT CARRIES `shippedAt` RATHER THAN LETTING A CONSUMER READ THE CLOCK, because
 * that timestamp starts the transit window that infers delivery (ADR-064) — and a
 * consumer computing "now" would get a different answer from the row whenever the
 * queue is behind.
 *
 * THE TRACKING NUMBER IS ON IT for the buyer notification S2's storefront work
 * will want; nothing consumes it yet.
 *
 * Order does NOT move on this event. A shipped order is still `paid` as far as
 * Order is concerned; the fulfilment state changes on DELIVERY (S2), which is the
 * only transition anything downstream waits for.
 *
 * @see docs/modules/Shipping.md §2
 */
final class ShipmentShipped extends BaseEvent
{
    public function __construct(
        public readonly string $shipmentUuid,
        public readonly string $orderUuid,
        public readonly string $sellerOrgUuid,
        public readonly string $cargoCompanyName,
        public readonly string $trackingNumber,
        public readonly string $shippedAt,
    ) {
        parent::__construct();
    }
}
