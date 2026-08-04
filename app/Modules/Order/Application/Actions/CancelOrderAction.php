<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Modules\Order\Domain\DTOs\CancelOrderDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Events\OrderCancelled;
use App\Modules\Order\Domain\Events\OrderCancelledBySeller;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stop an order and give the stock back (§3.3).
 *
 * ONE ACTION FOR FOUR DIFFERENT EVENTS, and the difference is carried rather than
 * flattened. A customer changing their mind, a seller refusing, an admin
 * intervening and a hold quietly expiring all end the same way — but the seller's
 * notification, the fraud signal and the abandonment metric all need to tell them
 * apart, so `cancelledBy` travels on the DTO, is RECORDED ON THE ORDER and rides
 * the event.
 *
 * THE ACTOR ALSO DECIDES WHAT HAPPENS TO THE SELLER'S SHELF (ADR-057):
 *
 *   BUYER            release. They changed their mind; the seller has the goods.
 *   SELLER           release **and zero the seller's on-hand** for that variant.
 *                    A merchant who cannot fulfil has told us something the
 *                    platform did not know — they have none — and putting the
 *                    units straight back on sale would send the next buyer into
 *                    the same wall. Anti-oversell.
 *   ADMIN            release; zero as well only when told to (a seller-fault
 *                    case). An oversight cancellation is not a claim about
 *                    anyone's stock.
 *   SYSTEM / EXPIRY  release. An abandoned tab says nothing about the shelf.
 *
 * THE ZERO HAPPENS AT THE OFFER, NOT HERE, and that is the point of doing it with
 * an event: the seller declares stock on the Offer form, so zeroing anywhere else
 * would leave their own screen disagreeing with Inventory. Order emits
 * `OrderCancelledBySeller`; the Offer consumes it by class-string and sets its
 * `stock_quantity` to 0, which flows through the existing Offer→Inventory mirror
 * (ADR-048). Order still imports nothing.
 *
 * EVERY LIVE ORDER RETURNS ITS STOCK, SINCE ADR-057, and that is the amendment
 * this file exists to carry. Placement no longer commits, so a placed order is
 * still HOLDING its units — cancelling it is the same plain `release` as
 * cancelling an un-placed one, and the stock comes back either way.
 *
 * BEFORE ADR-057 IT DID NOT. Placement committed, the units were gone from
 * `on_hand`, and `release()` on a committed reference is a documented no-op — so a
 * cancelled placed order silently kept the stock out of circulation, with no
 * primitive able to bring it back. That was Order §12.5 follow-up #1, and moving
 * the commit to Payment resolves it without Inventory needing an un-commit.
 *
 * POST-PAYMENT RETURNS ARE STILL OUT OF SCOPE. Once Payment commits a sale,
 * bringing units back needs an Inventory RESTOCK primitive with its own movement
 * type — reversing a sale is a different business event from abandoning a hold,
 * and conflating them in an append-only ledger makes "why did my stock go up"
 * unanswerable. That is the Returns sprint.
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
            /*
            | WHO, on the row and not only on the event. An event is consumed once;
            | this is the fact somebody asks about six months later when a customer
            | says "I never cancelled that", and the platform has to answer from its
            | own records.
            */
            'cancelled_by' => $data->cancelledBy,
            'cancellation_reason' => $data->reason,
        ])->save();

        return $order;
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

        if ($data->zeroesSellerStock()) {
            $this->announceSellerZero($order);
        }

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

    /**
     * Hand every hold back, per line, under the reference checkout reserved with.
     *
     * A MISSING HOLD MUST NOT BLOCK THE CANCELLATION, and that asymmetry is the
     * point of this method. Inventory's `release()` throws when nothing was ever
     * reserved under a reference — correct for it, since a caller releasing a
     * reference it never took is a bug worth surfacing. But from here the caller
     * is not buggy: an order can legitimately reach this point with no live hold
     * (an imported record, a reservation lost to a restore, a hold already given
     * back by another path), and refusing to cancel it would leave a customer with
     * an order nobody can stop.
     *
     * So each line is released on its own and a failure is LOGGED, not
     * propagated. The cost is that a genuine Inventory fault becomes a log line
     * rather than a refusal — accepted, because the alternative is an order that
     * cannot be cancelled at all, and the expiry sweep exists precisely to catch
     * holds that survive.
     *
     * `\Throwable` rather than `InventoryException`, and not by choice: Order
     * imports no module (`LayeringTest`), so it cannot name the exception type it
     * is expecting. The Core contract documents the failure in prose instead —
     * the one place that boundary costs something real.
     *
     * COMPARE `PlaceOrderAction`, which does NOT swallow: committing a hold that
     * does not exist would sell stock nobody reserved, so there the throw is the
     * right outcome. Releasing something twice is harmless; committing something
     * once too often is not.
     */
    private function releaseHolds(Order $order): void
    {
        foreach ($order->lines as $line) {
            $reference = $order->reservationReferenceFor($line->variant_uuid);

            try {
                $this->reservations->release($reference);
            } catch (Throwable $exception) {
                Log::channel('errors')->warning('Could not release a hold while cancelling an order', [
                    'order_uuid' => $order->uuid,
                    'reference' => $reference,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Tell the Offer that this seller has no stock (ADR-057, group C).
     *
     * ONE EVENT PER LINE, because "the seller has none" is a claim about a VARIANT,
     * not about an order. An order may carry several, and each one's offer has to
     * be zeroed independently — a single event naming the order would make the
     * consumer resolve the lines, which it cannot do without importing Order.
     *
     * It carries the offer, the variant and the selling org so the consumer can act
     * on any of them without a lookup: the Offer zeroes by offer uuid, and the
     * variant and org are there because Inventory's mirror is keyed on that pair
     * and a future consumer will want it.
     *
     * IN `after()`, so it fires only once the cancellation has actually committed.
     * A zeroed offer for an order that rolled back would take a seller's whole
     * variant off sale for nothing.
     */
    private function announceSellerZero(Order $order): void
    {
        foreach ($order->lines as $line) {
            OrderCancelledBySeller::dispatch(
                $order->uuid,
                $order->order_number,
                $line->offer_uuid,
                $line->variant_uuid,
                $order->selling_org_uuid,
            );
        }
    }
}
