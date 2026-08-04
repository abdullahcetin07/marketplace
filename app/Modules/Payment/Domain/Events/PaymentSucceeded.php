<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * Money arrived for a checkout group (Payment.md §3, §7).
 *
 * THE EVENT THAT MOVES EVERY OTHER MODULE. Payment commits the stock itself
 * (§5 — it is the caller ADR-057 named), but it does not confirm the orders:
 * Order owns its own state machine, and a module that reached in to set another's
 * status would be the boundary failing at its most tempting point. So this says
 * what happened and Order decides what that means for an order.
 *
 * CONSUMED BY CLASS-STRING, the platform's standard for a cross-module event —
 * Order names it as a string and reads public properties off a plain object,
 * exactly as Offer consumes `OrderCancelledBySeller` and Inventory consumes
 * Offer's stock events. No import, in either direction.
 *
 * IT CARRIES THE GROUP, NOT AN ORDER, because that is the grain money has: one
 * card, one basket, N orders. A per-order event would make every consumer
 * reassemble what the split took apart.
 *
 * DISPATCHED AFTER COMMIT (`BaseAction::after()`), so no listener can observe a
 * payment that a later failure rolls back — which for a listener that credits a
 * seller's balance (P3) is the difference between an accounting record and a lie.
 *
 * @see docs/modules/Payment.md §3
 */
final class PaymentSucceeded extends BaseEvent
{
    /**
     * @param array<int, string> $orderUuids
     */
    public function __construct(
        public readonly string $paymentUuid,
        public readonly string $checkoutGroupUuid,
        public readonly int $amountMinor,
        public readonly string $currencyCode,
        public readonly array $orderUuids,
    ) {
        parent::__construct();
    }
}
