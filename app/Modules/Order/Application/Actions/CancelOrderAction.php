<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Modules\Order\Domain\DTOs\CancelOrderDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Events\OrderCancelled;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\Order;

/**
 * Stop an order and give the stock back (§3.3).
 *
 * ONE ACTION FOR FOUR DIFFERENT EVENTS, and the difference is carried rather than
 * flattened. A customer changing their mind, a seller refusing, an admin
 * intervening and a hold quietly expiring all end the same way — but the seller's
 * notification, the fraud signal and the abandonment metric all need to tell them
 * apart, so `cancelledBy` travels on the DTO and on the event.
 *
 * WHAT GOES BACK DEPENDS ON WHAT WAS HELD, and this is the subtlety worth reading
 * twice:
 *
 *   `Pending`          → the units were HELD. `release()` hands the hold back.
 *   `AwaitingPayment`  → the units had COMMITTED and are gone from `on_hand`.
 *                        Releasing would lower `reserved` — which is already
 *                        zero for this reference — and the stock would never
 *                        return.
 *
 * SO A PLACED ORDER IS RESTOCKED, NOT RELEASED. Inventory has no "un-commit"
 * primitive and should not: reversing a sale is a different business event from
 * abandoning a hold, and conflating them in the ledger would make "why did my
 * stock go up" unanswerable. Until Inventory grows one, this action records the
 * cancellation and the stock stays out — a documented gap, surfaced as a
 * follow-up rather than papered over with a release that silently does nothing.
 *
 * IDEMPOTENT. Cancelling twice is a no-op, not an error: a double-clicked button
 * and a retried webhook are both ordinary, and Inventory's release is idempotent
 * on the reference underneath.
 *
 * @see docs/modules/Order.md §3.3
 */
final class CancelOrderAction extends BaseAction
{
    private bool $wasHoldingReservation = false;

    private bool $alreadyCancelled = false;

    public function __construct(
        private readonly InventoryReservationContract $reservations,
    ) {}

    public function handle(mixed ...$arguments): Order
    {
        /** @var Order $order */
        $order = $arguments[0];
        /** @var CancelOrderDTO $data */
        $data = $arguments[1];

        if ($order->status === OrderStatus::Cancelled) {
            // A double cancel does not double-release. Silent rather than a
            // refusal: the caller's intent is already satisfied.
            $this->alreadyCancelled = true;

            return $order;
        }

        if (! $order->status->canTransitionTo(OrderStatus::Cancelled)) {
            throw OrderException::invalidTransition($order->status, OrderStatus::Cancelled);
        }

        $this->wasHoldingReservation = $order->holdsReservation();

        if ($this->wasHoldingReservation) {
            $this->releaseHolds($order);
        }

        $order->forceFill([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $data->reason,
        ])->save();

        return $order;
    }

    /**
     * Hand every hold back, per line, under the reference checkout reserved with.
     */
    private function releaseHolds(Order $order): void
    {
        foreach ($order->lines as $line) {
            $this->reservations->release($order->reservationReferenceFor($line->variant_uuid));
        }
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->alreadyCancelled) {
            // Nothing changed, so nothing is announced — a listener notifying a
            // seller on every retry would be noise about a non-event.
            return;
        }

        /** @var Order $order */
        $order = $result;
        /** @var CancelOrderDTO $data */
        $data = $arguments[1];

        OrderCancelled::dispatch(
            (int) $order->getKey(),
            $order->uuid,
            $order->order_number,
            $order->checkout_group_uuid,
            (int) $order->customer_id,
            $order->customer_uuid,
            $order->selling_org_uuid,
            $data->cancelledBy,
            $data->reason,
            // The stock half of the same distinction: a listener should not have
            // to infer it from a status that now reads `Cancelled` either way.
            $this->wasHoldingReservation,
        );
    }
}
