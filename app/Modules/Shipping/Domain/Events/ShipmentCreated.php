<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A paid order now has a parcel waiting to be sent (ADR-063).
 *
 * NOTHING CONSUMES IT IN S1, and it ships anyway for one reason: the seller
 * notification ("bir siparişiniz var, kargoya verin") is Notification's to add and
 * needs no change here when it does. An event with no listener is cheap; a
 * listener that has to be retrofitted into an action is not.
 *
 * @see docs/modules/Shipping.md §2
 */
final class ShipmentCreated extends BaseEvent
{
    public function __construct(
        public readonly string $shipmentUuid,
        public readonly string $orderUuid,
        public readonly string $sellerOrgUuid,
        public readonly string $orderNumber,
    ) {
        parent::__construct();
    }
}
