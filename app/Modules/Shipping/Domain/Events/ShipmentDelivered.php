<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * The parcel arrived — **the event this whole module exists to emit**
 * (ADR-064, Shipping.md §4).
 *
 * TWO CLOCKS START HERE, and neither belongs to Shipping: the seller's payout
 * becomes eligible at `deliveredAt + payout_hold_days`, and the buyer may return
 * until `deliveredAt + return_days` (S3). Payment subscribes BY CLASS-STRING and
 * Order moves its own fulfilment state the same way — this module announces a
 * fact and drives nothing.
 *
 * `deliveredAt` IS ON THE PAYLOAD RATHER THAN LEFT TO A CONSUMER'S CLOCK, and
 * that is the whole reason this event carries data at all. A queued listener that
 * read `now()` would compute a different payout date from the one on the row
 * whenever the queue is behind — and a payout date that depends on queue latency
 * is one nobody can reconcile.
 *
 * `deliveredVia` SAYS HOW MUCH THE DATE IS WORTH. A buyer who confirmed and a
 * clock that ran out are worth different amounts in a dispute, and a consumer
 * deciding whether to auto-pay a seller may reasonably care which it was. Carried
 * as a string, like every other enum value that crosses a module boundary.
 *
 * IT IS EMITTED ONCE PER SHIPMENT. Both paths that produce it — the buyer's
 * confirmation and the transit sweep — refuse to act on a shipment that is no
 * longer in transit, so a listener that credits or schedules can trust it.
 *
 * @see docs/modules/Shipping.md §3, §4
 */
final class ShipmentDelivered extends BaseEvent
{
    public function __construct(
        public readonly string $shipmentUuid,
        public readonly string $orderUuid,
        public readonly string $sellerOrgUuid,
        public readonly string $deliveredAt,
        public readonly string $deliveredVia,
    ) {
        parent::__construct();
    }
}
