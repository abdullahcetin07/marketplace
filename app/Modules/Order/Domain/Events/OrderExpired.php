<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A placed order ran out of payment window and gave its stock back (ADR-072).
 *
 * **A HOOK WITH NO LISTENER, DELIBERATELY.** The obvious consumers are a
 * "siparişiniz düştü" notice to the customer and a Payment-side move of the
 * `Payment` row to its own `Expired` state — neither ships in v1 — but the event
 * fires now, so each is a new class rather than a change to this module.
 *
 * **IT CARRIES THE CHECKOUT GROUP, and that is the field a future consumer
 * actually needs.** A `Payment` is keyed on the group, not on an order
 * (ADR-052/060), so a listener that wanted to mark the payment expired could not
 * find it from an order uuid alone without asking Order back.
 *
 * IT IS NOT THE RELEASE ITSELF. The hold is already back by the time this fires
 * — `ExpireOrderAction` releases inside its transaction and dispatches after
 * commit — so no listener has to, or should, touch stock.
 *
 * @see App\Modules\Order\Application\Actions\ExpireOrderAction
 */
final class OrderExpired extends BaseEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderUuid,
        public readonly string $checkoutGroupUuid,
    ) {
        parent::__construct();
    }
}
