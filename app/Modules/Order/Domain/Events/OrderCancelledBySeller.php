<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A seller cancelled an order because they cannot fulfil it — so they have no
 * stock (ADR-057, §3.3).
 *
 * NOT A SECOND "ORDER CANCELLED" EVENT. `OrderCancelled` says the order stopped
 * and who stopped it; this says something different and narrower: **this seller
 * does not have this variant**. It is a claim about a shelf, not about an order,
 * which is why it is per LINE rather than per order and why its payload leads with
 * the offer rather than with the order number.
 *
 * IT EXISTS SO THE ZERO CAN HAPPEN AT THE SOURCE. The seller declares stock on the
 * Offer form (ADR-048), so zeroing it anywhere else would leave their own screen
 * disagreeing with Inventory. The **Offer** consumes this by class-string — no
 * import of Order, the pattern it already uses for Catalog's lifecycle events —
 * sets `stock_quantity` to 0, and its existing `OfferStockChanged` carries that
 * through the Offer→Inventory mirror to `on_hand`. Three modules, no imports.
 *
 * WHY ANTI-OVERSELL RATHER THAN JUST RELEASING: a merchant who cannot fulfil has
 * told the platform something it did not know. Releasing the hold alone would put
 * the units straight back on sale and send the next buyer into the same wall —
 * and the one after that. Sales stop until the seller re-declares what they
 * actually have.
 *
 * THE SELLER IS WARNED BEFORE THIS FIRES. The confirm dialog says their stock will
 * be zeroed, because a cancellation that quietly delists a whole variant would be
 * a nasty surprise.
 *
 * @see docs/modules/Order.md §3.3
 */
final class OrderCancelledBySeller extends BaseEvent
{
    public function __construct(
        public readonly string $orderUuid,
        public readonly string $orderNumber,
        public readonly string $offerUuid,
        public readonly string $variantUuid,
        public readonly string $sellingOrgUuid,
    ) {
        parent::__construct();
    }
}
