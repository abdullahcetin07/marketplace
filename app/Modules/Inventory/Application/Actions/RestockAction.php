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

    private StockReservation $reservation;

    public function __construct(
        private readonly StockItemRepositoryContract $items,
    ) {}

    public function handle(mixed ...$arguments): ?StockItem
    {
        $reference = (string) $arguments[0];

        $reservation = $this->items->findReservation($reference);

        if ($reservation === null) {
            throw InventoryException::reservationNotFound($reference);
        }

        $this->reservation = $reservation;

        if (! $reservation->isRestockable()) {
            // Already restocked, or never sold. Both are no-ops rather than
            // errors: a retried refund must not inflate a seller's stock, and a
            // released hold has nothing to give back.
            return $this->items->lockForReservation($reservation);
        }

        $item = $this->items->lockForReservation($reservation);

        if ($item === null) {
            throw InventoryException::reservationNotFound($reference);
        }

        $this->wasCommitted = true;

        $reservation->forceFill([
            'status' => ReservationStatus::Restocked,
            'restocked_at' => now(),
        ])->save();

        $this->recordMovement(
            $item,
            StockMovementType::Restocked,
            // ON-HAND ONLY. @see the class docblock for why `reserved` stays put.
            onHandDelta: $reservation->quantity,
            reservedDelta: 0,
            reference: $reference,
        );

        return $item;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        if (! $this->wasCommitted || ! $result instanceof StockItem) {
            return;
        }

        StockRestocked::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->selling_org_uuid,
            $this->reservation->quantity,
            $this->reservation->reference,
            $result->on_hand,
            $result->available(),
        );
    }
}
