<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * The read port other modules use to ask where a parcel is — WITHOUT importing
 * Shipping (ADR-065, Shipping.md §10).
 *
 * **IT EXISTS BECAUSE CANCELLATION IS GATED ON A FACT SHIPPING OWNS.** ADR-065
 * put the gate at the shipment state rather than at a time window: a paid order
 * may be cancelled while the parcel is still `pending`, and once it is `shipped`
 * cancellation is gone and ADR-064's return takes over. Payment is where the
 * refund lives, so Payment has to ask that question — and Payment imports no
 * module.
 *
 * A STRING, NOT THE ENUM, for the reason `OrderQueryContract::orderStatus()`
 * gives: `ShipmentStatus` is Shipping's, and typing the port with it would make
 * every consumer import the module the port exists to avoid importing. The values
 * are stable — they are what the column holds.
 *
 * **NULL IS NOT "PENDING".** An order with no shipment row is one this port
 * cannot vouch for, and a caller that reads the absence as "not shipped yet" is
 * making the most expensive possible guess: refunding a parcel that is already
 * with a carrier. Every caller must refuse on null.
 *
 * NO COMMAND HALF. Nothing outside Shipping may ship, deliver or cancel a
 * parcel — a shipment's state machine is Shipping's, and other modules move it by
 * announcing facts it subscribes to (a cancellation refund, ADR-065; a return,
 * S4). Compare `InventoryReservationContract`, which IS a command port because
 * stock is a resource other contexts legitimately borrow.
 *
 * @see App\Modules\Shipping\Infrastructure\Queries\ShipmentQuery
 * @see docs/modules/Shipping.md §10
 */
interface ShipmentQueryContract
{
    /**
     * The order's shipment status as its string value (`pending`, `shipped`,
     * `delivered`, `returned`, `cancelled`); **null when the order has no
     * shipment at all**.
     *
     * @see the class docblock for why null must never be read as "pending".
     */
    public function shipmentStatusFor(string $orderUuid): ?string;

    /**
     * Whether the seller may still hand this parcel over — the ADR-065 gate,
     * asked as the question the caller actually has.
     *
     * IT IS A SECOND METHOD RATHER THAN A COMPARISON AT EACH CALL SITE, because
     * "which statuses count as pre-shipment" is Shipping's to answer and the
     * moment two callers spell it out themselves is the moment they disagree.
     * False for an order with no shipment, per the class docblock.
     */
    public function isAwaitingHandover(string $orderUuid): bool;
}
