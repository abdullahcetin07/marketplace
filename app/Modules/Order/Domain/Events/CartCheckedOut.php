<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A customer checked out: the cart split into N orders and the stock is HELD
 * (ADR-052/054, §6).
 *
 * ONE EVENT FOR THE WHOLE PURCHASE, not one per order — because the purchase is
 * what the customer did. `OrderPlaced` is per order, and the difference matters
 * to consumers: a "thanks for your order" email is one message about a checkout
 * group, while a seller notification is one message about their own order.
 *
 * NOTHING IS PAID AND NOTHING IS PROMISED YET. Each order is `Pending`, its stock
 * reserved on a clock (config `order.reservation.expires_after_minutes`), and a
 * customer who wanders off has this all released under them. A listener that
 * treats this as a sale will be wrong roughly as often as carts are abandoned.
 *
 * INVENTORY IS NOT DRIVEN BY THIS EVENT. The reserve already happened, inside the
 * checkout transaction, through the Core command contract (ADR-054) — an event is
 * a notification, and stock is too important to move on one that a listener might
 * fail to handle.
 *
 * @see docs/modules/Order.md §6
 */
final class CartCheckedOut extends BaseEvent
{
    /**
     * @param array<int, string> $orderUuids every order the checkout produced
     */
    public function __construct(
        public readonly string $checkoutGroupUuid,
        public readonly int $customerId,
        public readonly string $customerUuid,
        public readonly array $orderUuids,
        // The whole purchase, in minor units — the number a customer receipt
        // shows and no single order carries (ADR-052).
        public readonly int $grandTotalMinor,
        public readonly string $currencyCode,
    ) {
        parent::__construct();
    }
}
