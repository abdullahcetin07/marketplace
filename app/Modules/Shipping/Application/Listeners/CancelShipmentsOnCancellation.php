<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Application\Listeners;

use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\Shipment;

/**
 * The parcel that will never be packed (ADR-065, C1).
 *
 * **THE MIRROR OF `MarkShipmentsReturned`, AND A SEPARATE CLASS ON PURPOSE.** Both
 * subscribe to `PaymentRefunded`; they set different statuses, from different
 * starting states, for opposite halves of the lifecycle — a return closes a parcel
 * that arrived, this closes one that never left. A single listener branching on
 * the event's `cause` would put two unrelated transition guards in one method and
 * make "why did this shipment change?" a question with two answers in one place.
 *
 * IT READS `cause`, WHICH IS THE ONLY THING THAT TELLS THEM APART. A refund is a
 * refund on the money side whichever end of the lifecycle it happened at; the
 * event says which, and each listener ignores the other's. **The transition table
 * would catch a mix-up anyway** — a `pending` shipment cannot become `returned` and
 * a `delivered` one cannot become `cancelled` — but relying on that would be
 * relying on a coincidence to enforce a rule.
 *
 * SUBSCRIBED BY CLASS-STRING, so Shipping imports nothing from Payment. The
 * handler takes an untyped `object` and reads public properties: the event's SHAPE
 * is the whole contract.
 *
 * **ONLY A FULLY CANCELLED ORDER MOVES.** Payment names an order on this event
 * exactly when every unit of every line has gone back. A seller who cancelled one
 * of two units still has a parcel to send — with one item in it.
 *
 * @see docs/modules/Shipping.md §2
 */
final class CancelShipmentsOnCancellation
{
    /**
     * `App\Modules\Payment\Domain\Events\PaymentRefunded` — untyped on purpose.
     */
    public function handle(object $event): void
    {
        if (($event->cause ?? null) !== 'cancellation') {
            // A return. `MarkShipmentsReturned` owns that end of the lifecycle.
            return;
        }

        /** @var array<int, string> $orderUuids */
        $orderUuids = $event->orderUuids ?? [];

        if ($orderUuids === []) {
            // Part of the order survives, so part of the parcel does.
            return;
        }

        foreach (Shipment::query()->whereIn('order_uuid', $orderUuids)->get() as $shipment) {
            if (! $shipment->status->canTransitionTo(ShipmentStatus::Cancelled)) {
                // Already handed over, or already cancelled. Both are silence.
                continue;
            }

            $shipment->forceFill([
                'status' => ShipmentStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();
        }
    }
}
