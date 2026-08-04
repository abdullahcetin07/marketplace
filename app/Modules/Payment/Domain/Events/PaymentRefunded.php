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
    ) {
        parent::__construct();
    }
}
