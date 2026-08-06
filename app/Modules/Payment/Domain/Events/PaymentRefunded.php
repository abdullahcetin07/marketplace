<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * Money went back to the buyer for some or all of a basket (Payment.md §8, P5).
 *
 * THE MIRROR OF `PaymentSucceeded`, AND THE SAME BOUNDARY. Payment restocks the
 * units itself — it is the only module holding the reservation references — but it
 * does not set an order's status, because Order owns its own state machine. This
 * says what happened; the class-string listener in Order decides what it means for
 * an order.
 *
 * IT CARRIES THE ORDERS THAT WERE REFUNDED, not the group, and that is the one
 * shape difference from its mirror. A payment succeeds for the whole basket at
 * once; it is refunded one seller's order at a time, so the list is the payload.
 * A consumer that assumed "all of them" would refund four sellers' orders because
 * one buyer returned a fifth seller's parcel.
 *
 * IT CARRIES WHY, NOT JUST WHAT (ADR-065). `cause` distinguishes a post-delivery
 * RETURN from a pre-shipment CANCELLATION — the money is identical and the
 * downstream meaning is not, and no consumer could tell them apart from the
 * amounts. Defaulted to `return`, so P5's construction sites did not change.
 *
 * DISPATCHED AFTER COMMIT, like every other event on this platform: no listener
 * may observe a refund a later failure rolls back.
 *
 * @see docs/modules/Payment.md §8
 */
final class PaymentRefunded extends BaseEvent
{
    /**
     * @param array<int, string> $orderUuids the orders this refund covered
     */
    public function __construct(
        public readonly string $paymentUuid,
        public readonly string $checkoutGroupUuid,
        public readonly int $amountMinor,
        public readonly string $currencyCode,
        public readonly array $orderUuids,
        /**
         * Whether the whole payment is now refunded, or only part of it — the
         * same question the Payment's own status answers, carried so a consumer
         * does not have to ask.
         */
        public readonly bool $fullyRefunded,
        /**
         * WHY the money went back — `return` or `cancellation` (ADR-065).
         *
         * A STRING RATHER THAN `RefundCause`, and that is the whole reason the
         * enum is not on this signature: the consumers subscribe BY CLASS-STRING
         * so they import nothing from Payment, and a typed payload would undo
         * that in one hint. The event's shape is the contract.
         *
         * IT DECIDES WHAT A REFUND MEANS DOWNSTREAM. The amounts are identical
         * either way; the order becomes `refunded` or `cancelled` and the parcel
         * `returned` or `cancelled` on the strength of this field alone.
         */
        public readonly string $cause = 'return',
        /**
         * What the person who triggered it typed, if anything.
         *
         * CARRIED BECAUSE A CANCELLED ORDER HAS TO SHOW ONE (ADR-065). A seller
         * refusing to fulfil somebody's purchase owes them a sentence, and the
         * order screen is where the buyer looks — the refund row alone is an
         * admin surface they will never see.
         */
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }
}
