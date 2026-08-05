<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Application\Actions\Concerns;

use App\Modules\Shipping\Domain\Enums\DeliveredVia;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Events\ShipmentDelivered;
use App\Modules\Shipping\Domain\Models\Shipment;

/**
 * The one way a parcel becomes delivered (ADR-064).
 *
 * SHARED BY THE TWO HONEST PATHS — the buyer's confirmation and the transit sweep
 * — and that is the point. Delivery writes three columns and produces the event
 * two other modules key their clocks off; two copies of that would eventually
 * differ by one field, and the field that went missing would be `delivered_via`,
 * which is exactly the one a dispute turns on.
 *
 * **THE GUARD IS HERE, NOT IN THE CALLERS.** Only a shipment in transit may be
 * delivered: a `pending` parcel nobody handed to a carrier cannot have arrived,
 * and one already delivered must not have its date moved — that date is a payout
 * schedule and a return deadline, and re-stamping it would silently extend both.
 * Returning null rather than throwing is what makes the sweep idempotent and a
 * buyer's second tap harmless.
 *
 * IT DOES NOT DISPATCH. The event is handed back so the caller can fire it AFTER
 * COMMIT — no listener may schedule a payout against a transaction that then
 * rolls back.
 */
trait RecordsDelivery
{
    /**
     * Mark it delivered, and hand back the event to dispatch after commit.
     *
     * Null means it was not in transit — already delivered, or never shipped.
     */
    protected function recordDelivery(Shipment $shipment, DeliveredVia $via): ?ShipmentDelivered
    {
        if (! $shipment->status->isInTransit()) {
            return null;
        }

        $shipment->forceFill([
            'status' => ShipmentStatus::Delivered,
            'delivered_at' => now(),
            'delivered_via' => $via,
        ])->save();

        return new ShipmentDelivered(
            shipmentUuid: $shipment->uuid,
            orderUuid: $shipment->order_uuid,
            sellerOrgUuid: $shipment->seller_org_uuid,
            // ISO FROM THE ROW, not from a consumer's clock: a listener that read
            // `now()` would compute a different payout date whenever the queue is
            // behind, and a payout date that depends on queue latency is one
            // nobody can reconcile.
            deliveredAt: (string) $shipment->delivered_at?->toIso8601String(),
            deliveredVia: $via->value,
        );
    }
}
