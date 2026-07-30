<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * One seller's order was placed: its reservation is COMMITTED and it awaits
 * payment (ADR-054, §6).
 *
 * THE EVENT PAYMENT WILL SUBSCRIBE TO. It is per ORDER rather than per checkout
 * group because everything downstream of it is per seller — the seller's
 * notification, the fulfilment, and eventually the commission and the payout.
 *
 * IT CARRIES THE SELLER AND THE TOTALS, so a consumer that may not import Order
 * can act without reading anything back: a notification names the shop, and a
 * future Payment opens a charge for an amount. Both are on the payload for the
 * reason Offer's stock events carry the org id/uuid pair — a listener reached by
 * class-string cannot resolve one identifier from another.
 *
 * WHAT IT DOES NOT MEAN: that money has moved. Nothing is charged this sprint
 * (ADR-055). It means the units have left the seller's shelf and the customer
 * owes for them.
 *
 * @see docs/modules/Order.md §6
 */
final class OrderPlaced extends BaseEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderUuid,
        public readonly string $orderNumber,
        public readonly string $checkoutGroupUuid,
        public readonly int $customerId,
        public readonly string $customerUuid,
        public readonly string $sellingOrgUuid,
        public readonly string $storeUuid,
        public readonly int $grandTotalMinor,
        public readonly int $taxTotalMinor,
        public readonly string $currencyCode,
    ) {
        parent::__construct();
    }
}
