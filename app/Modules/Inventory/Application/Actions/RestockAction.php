<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Inventory\Application\Actions\Concerns\RecordsMovements;
use App\Modules\Inventory\Domain\Contracts\StockItemRepositoryContract;
use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Events\StockRestocked;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockReservation;

/**
 * Units that were SOLD have come back — a refund or a return (Payment.md §8, P5).
 *
 * THE PRIMITIVE ORDER.md §12.5 DEFERRED, and the reason it was deferred is the
 * reason it looks like this. That follow-up said Inventory "has no un-commit and
 * must not grow one by side effect — reversing a sale is a different business
 * event from abandoning a hold, and conflating them in the append-only ledger
 * makes 'why did my stock go up?' unanswerable". So this is not `release` with a
 * different name: it has its own movement type, its own reservation state and its
 * own timestamp, and the ledger says which of the two happened.
 *
 * IT PUTS BACK ON-HAND AND NOTHING ELSE. `commit` lowered both `on_hand` and
 * `reserved`; the reserved half was the hold ending, and the hold does not come
 * back — the units are simply on the shelf again, unheld and sellable. Restoring
 * `reserved` too would hold stock for an order that has been refunded.
 *
 * A REPEAT IS A NO-OP, the same guarantee `commit` gives and for a larger reason:
 * a retried refund that restocked twice would invent stock that does not
 * physically exist, and a seller would oversell it to somebody.
 *
 * ONLY A COMMITTED RESERVATION IS RESTOCKABLE. An active hold is released; a
 * released one never left. Asking to restock either is a caller bug worth
 * surfacing rather than silently absorbing.
 *
 * @see docs/modules/Inventory.md §3.2
 * @see docs/modules/Payment.md §8
 */
final class RestockAction extends BaseAction
{
    use RecordsMovements;

    private bool $wasCommitted = false;

    /**
     * How many units this run actually put back — the event's payload, and 0 when
     * the call was a no-op.
     */
    private int $restocked = 0;

    private StockReservation $reservation;

    public function __construct(
        private readonly StockItemRepositoryContract $items,
    ) {}

    public function handle(mixed ...$arguments): ?StockItem
    {
        $reference = (string) $arguments[0];
        /*
        | NULL MEANS "ALL OF IT" (S4). P5's refund was whole-order and so was this
        | verb; a line-level refund returns a QUANTITY, and a caller that omits it
        | still gets the P5 behaviour.
        */
        $quantity = isset($arguments[1]) ? (int) $arguments[1] : null;

        $reservation = $this->items->findReservation($reference);

        if ($reservation === null) {
            throw InventoryException::reservationNotFound($reference);
        }

        $this->reservation = $reservation;

        if (! $reservation->isRestockable()) {
            // Released or never sold. A no-op rather than an error: there is
            // nothing to give back.
            return $this->items->lockForReservation($reservation);
        }

        $item = $this->items->lockForReservation($reservation);

        if ($item === null) {
            throw InventoryException::reservationNotFound($reference);
        }

        /*
        | HOW MANY ARE ACTUALLY STILL OUT THERE. A caller asking for more than the
        | buyer ever had gets what is left, not what they asked for — an inflated
        | restock invents stock that does not physically exist, and the seller
        | oversells it to somebody.
        */
        $remaining = $reservation->remainingToRestock();
        $returning = $quantity === null ? $remaining : min(max(0, $quantity), $remaining);

        if ($returning === 0) {
            // Everything is already home. The idempotency P5 promised, now
            // expressed as arithmetic rather than as a status.
            return $item;
        }

        $this->restocked = $returning;

        $restockedTotal = $reservation->restocked_quantity + $returning;

        $reservation->forceFill([
            // TERMINAL ONLY WHEN THE LAST UNIT IS HOME. A partly returned
            // reservation stays `Committed`, because part of the sale still
            // stands.
            'status' => $restockedTotal >= $reservation->quantity
                ? ReservationStatus::Restocked
                : $reservation->status,
            'restocked_quantity' => $restockedTotal,
            'restocked_at' => $restockedTotal >= $reservation->quantity ? now() : $reservation->restocked_at,
        ])->save();

        $this->recordMovement(
            $item,
            StockMovementType::Restocked,
            // ON-HAND ONLY. @see the class docblock for why `reserved` stays put.
            onHandDelta: $returning,
            reservedDelta: 0,
            reference: $reference,
        );

        return $item;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        if (! $this->wasCommitted || $this->restocked === 0 || ! $result instanceof StockItem) {
            return;
        }

        StockRestocked::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->selling_org_uuid,
            $this->restocked,
            $this->reservation->reference,
            $result->on_hand,
            $result->available(),
        );
    }
}
