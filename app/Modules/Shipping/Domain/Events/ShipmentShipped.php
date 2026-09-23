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
        /*
        | **THE CARRIER'S OWN TRACKING PAGE, RESOLVED HERE** (2026-09-23). Order
        | mails the buyer when a parcel leaves, and a tracking number they must
        | copy into a search engine is a worse answer than a link. The template
        | lives on `cargo_companies`, which is Shipping's table — so the event
        | carries the finished URL rather than the consumer importing this module
        | to build it. Adding a field to an event is exactly what the approval
        | anticipated instead (Inventory.md §10.4).
        |
        | Null when the carrier has no template configured: most do, an operator
        | may not have filled one in, and a missing link is not worth losing the
        | e-mail over.
        */
        public readonly ?string $trackingUrl = null,
    ) {
        parent::__construct();
    }
}
