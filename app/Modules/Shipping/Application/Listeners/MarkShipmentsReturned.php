<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Application\Listeners;

use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\Shipment;

/**
 * The goods came back (S4, Shipping.md §2).
 *
 * THE LAST TRANSITION IN THE PARCEL'S LIFE, and the only one this module does not
 * originate: `returned` is driven by Payment's refund, exactly as §2's diagram
 * said it would be. Shipping still owns the state — Payment announces and this
 * moves it, the same division Order keeps for `PaymentSucceeded`.
 *
 * SUBSCRIBED BY CLASS-STRING, so Shipping imports nothing from Payment. The
 * handler takes an untyped `object` and reads public properties: the event's
 * SHAPE is the whole contract.
 *
 * **ONLY A FULLY RETURNED ORDER MOVES.** Payment names an order on this event
 * exactly when every unit of every line has gone back — a buyer returning one of
 * two shoes has a parcel that was still delivered, and marking it `returned` would
 * tell every screen the whole thing came back.
 *
 * IDEMPOTENT BY THE TRANSITION TABLE: only a `delivered` shipment may become
 * `returned`, so a replayed event finds nothing to do.
 *
 * @see docs/modules/Shipping.md §2
 */
final class MarkShipmentsReturned
{
    /**
     * `App\Modules\Payment\Domain\Events\PaymentRefunded` — untyped on purpose.
     */
    public function handle(object $event): void
    {
        if (($event->cause ?? 'return') !== 'return') {
            /*
            | A PRE-SHIPMENT CANCELLATION (ADR-065). Same event, opposite end of
            | the lifecycle — `CancelShipmentsOnCancellation` owns it. The
            | transition table would refuse this anyway (a `pending` parcel cannot
            | become `returned`), but relying on that would be relying on a
            | coincidence to enforce a rule.
            */
            return;
        }

        /** @var array<int, string> $orderUuids */
        $orderUuids = $event->orderUuids ?? [];

        if ($orderUuids === []) {
            // A partial return. The parcel is still a delivered parcel.
            return;
        }

        foreach (Shipment::query()->whereIn('order_uuid', $orderUuids)->get() as $shipment) {
            if (! $shipment->status->canTransitionTo(ShipmentStatus::Returned)) {
                // Never delivered, or already returned. Both are silence.
                continue;
            }

            $shipment->forceFill([
                'status' => ShipmentStatus::Returned,
                'returned_at' => now(),
            ])->save();
        }
    }
}
