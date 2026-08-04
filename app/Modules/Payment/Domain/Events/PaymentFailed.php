<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A payment attempt ended without money (Payment.md §3).
 *
 * DECLINED, ABANDONED OR EXPIRED — all the same to a consumer, which is why one
 * event carries `reason` rather than three events carrying none. The distinction
 * that matters operationally is recorded on the Payment's own status.
 *
 * THE STOCK IS ALREADY RELEASED by the time this fires: Payment releases the
 * reservations it was handed inside the callback's transaction (§5), because the
 * units must go back on sale whether or not anybody is listening. This event is
 * what lets Order move its own orders, and later what lets a "your payment did
 * not go through" mail be sent.
 *
 * Consumed by class-string. No import, in either direction.
 *
 * @see docs/modules/Payment.md §3
 */
final class PaymentFailed extends BaseEvent
{
    /**
     * @param array<int, string> $orderUuids
     */
    public function __construct(
        public readonly string $paymentUuid,
        public readonly string $checkoutGroupUuid,
        public readonly array $orderUuids,
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }
}
