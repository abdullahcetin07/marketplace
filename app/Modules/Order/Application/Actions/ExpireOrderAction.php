<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Events\OrderExpired;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The payment window ran out: give the stock back (ADR-072).
 *
 * **THIS IS THE FIX FOR A LIVE, MONEY-CRITICAL BUG.** ADR-057 made placement
 * HOLD a reservation and Payment commit it — and nothing released the hold if the
 * customer simply closed the tab at the card form. The hold sat forever, a
 * seller's `available = on_hand − reserved` fell toward zero, and their offer
 * dropped off the buy box **while still declaring stock**. Every abandoned
 * checkout permanently cost that seller some of their inventory.
 *
 * **IT IS AN EXPIRY, NOT A CANCELLATION**, and the distinction is why this is its
 * own action rather than another `CancelOrderDTO::BY_EXPIRY` call. `CancelOrderAction`
 * exists for a DECISION — somebody chose to end this order, it is terminal, and
 * for a seller it also zeroes their declared stock (ADR-057). None of that is
 * true here: nobody decided anything, the clock did; the order must stay open to
 * a payment that lands late; and zeroing a seller's stock because a stranger
 * abandoned a basket would be the opposite of the bug being fixed.
 *
 * **THE GUARD MAKES IT IDEMPOTENT AGAINST THE RACE THAT MATTERS.** The sweep runs
 * every minute and PayTR's callback can arrive at any moment; an order that has
 * already become `Paid` between the batch being read and this action running is
 * skipped rather than expired, so a paid order never loses its commit.
 *
 * A FAILED RELEASE IS LOGGED, NEVER FATAL — the same shape `CancelOrderAction`
 * uses. Inventory's `release()` is already idempotent and a no-op on a hold that
 * was released or committed; what is left is a genuinely missing reservation,
 * which is a data question for a human and not a reason to strand the status.
 *
 * @see docs/modules/Order.md §3.3
 */
final class ExpireOrderAction extends BaseAction
{
    private ?OrderExpired $expired = null;

    public function __construct(private readonly InventoryReservationContract $reservations) {}

    public function handle(mixed ...$arguments): Order
    {
        /** @var Order $order */
        $order = $arguments[0];

        if ($order->status !== OrderStatus::AwaitingPayment) {
            /*
            | ALREADY PAID, ALREADY CANCELLED, ALREADY EXPIRED. Silence rather
            | than a refusal: the sweep reads a batch and then acts on it one row
            | at a time, so losing a race to the callback is ordinary rather than
            | exceptional — and re-expiring is the one outcome that could undo a
            | commit.
            */
            return $order;
        }

        $this->releaseHolds($order);

        $order->forceFill([
            'status' => OrderStatus::Expired,
            /*
            | NO `cancelled_at`, AND NO NEW COLUMN EITHER. `cancelled_at` carries
            | "somebody ended this and here is when", which is exactly what did
            | not happen; borrowing it would make every cancellation report count
            | abandoned baskets. `placed_at` + the status already say when the
            | window started and how it ended, and `updated_at` says when the
            | sweep ran.
            */
        ])->save();

        $this->expired = new OrderExpired(
            orderId: (int) $order->getKey(),
            orderUuid: $order->uuid,
            checkoutGroupUuid: $order->checkout_group_uuid,
        );

        return $order;
    }

    /**
     * Dispatched AFTER COMMIT, so nothing acts on an expiry a later failure
     * rolls back. No listener ships in v1 (@see `OrderExpired`).
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->expired !== null) {
            event($this->expired);
        }
    }

    /**
     * Give every line's hold back — the entire point of the action.
     *
     * ON-HAND IS UNTOUCHED because the units never left: a held reservation
     * lowers `available`, not `on_hand` (ADR-048), so releasing it restores the
     * seller's availability without inventing stock.
     */
    private function releaseHolds(Order $order): void
    {
        foreach ($order->lines as $line) {
            $reference = $order->reservationReferenceFor($line->variant_uuid);

            try {
                $this->reservations->release($reference);
            } catch (Throwable $exception) {
                Log::channel('errors')->warning('Could not release a hold while expiring an order', [
                    'order_uuid' => $order->uuid,
                    'reference' => $reference,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
