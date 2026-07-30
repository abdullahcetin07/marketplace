<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An order was cancelled and its stock went back (§3.3, §6).
 *
 * IT CARRIES WHO AND WHY, because "cancelled" alone is the least useful fact
 * about a cancellation. A customer changing their mind, a seller refusing an
 * order, an admin intervening and a reservation quietly expiring are four
 * different business events that a single status column flattens into one — and
 * the seller's notification, the fraud signal and the abandonment metric all need
 * to tell them apart.
 *
 * `wasHoldingReservation` IS THE STOCK HALF of the same distinction: a `Pending`
 * order gave back a HOLD, while an `AwaitingPayment` one put COMMITTED units back
 * on the shelf. The release already happened through the Core contract inside the
 * transaction (ADR-054); this says which of the two it was, so a listener does not
 * have to infer it from a status that is now `Cancelled` either way.
 *
 * @see docs/modules/Order.md §3.3, §6
 */
final class OrderCancelled extends BaseEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderUuid,
        public readonly string $orderNumber,
        public readonly string $checkoutGroupUuid,
        public readonly int $customerId,
        public readonly string $customerUuid,
        public readonly string $sellingOrgUuid,
        /** Who cancelled: 'customer', 'seller', 'admin' or 'expiry'. */
        public readonly string $cancelledBy,
        public readonly ?string $reason,
        public readonly bool $wasHoldingReservation,
    ) {
        parent::__construct();
    }
}
