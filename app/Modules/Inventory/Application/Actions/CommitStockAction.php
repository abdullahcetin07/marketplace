<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Inventory\Application\Actions\Concerns\RecordsMovements;
use App\Modules\Inventory\Domain\Contracts\StockItemRepositoryContract;
use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Events\StockCommitted;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockReservation;

/**
 * Turn a hold into a sale: the units leave (§0.4, ADR-049).
 *
 * THE ONLY PLACE ON-HAND FALLS for a reason other than the seller editing it —
 * and this sprint nothing in production calls it, because Order does not exist.
 * That is the argument for building the authority first (ADR-049): Order will
 * find a tested primitive rather than inventing reservation semantics beside a
 * payment integration.
 *
 * BOTH NUMBERS FALL TOGETHER, and by the same amount. The units physically go,
 * and the hold that was covering them ends with them — leaving `reserved` behind
 * would double-count the sale against the seller's remaining availability
 * forever.
 *
 * A REPEAT IS A NO-OP. A retried order confirmation must not decrement twice,
 * which is the single most expensive bug this shape prevents: unlike a lost
 * reservation, a double commit destroys stock that physically exists.
 *
 * @see docs/modules/Inventory.md §3.2
 */
final class CommitStockAction extends BaseAction
{
    use RecordsMovements;

    private bool $wasActive = false;

    private StockReservation $reservation;

    public function __construct(
        private readonly StockItemRepositoryContract $items,
    ) {}

    public function handle(mixed ...$arguments): ?StockItem
    {
        $referenceUuid = (string) $arguments[0];

        $reservation = $this->items->findReservation($referenceUuid);

        if ($reservation === null) {
            throw InventoryException::reservationNotFound($referenceUuid);
        }

        $this->reservation = $reservation;

        if (! $reservation->isActive()) {
            return $this->items->lockForReservation($reservation);
        }

        $item = $this->items->lockForReservation($reservation);

        if ($item === null) {
            throw InventoryException::reservationNotFound($referenceUuid);
        }

        $this->wasActive = true;

        $reservation->forceFill([
            'status' => ReservationStatus::Committed,
            'committed_at' => now(),
        ])->save();

        $this->recordMovement(
            $item,
            StockMovementType::Committed,
            onHandDelta: -$reservation->quantity,
            reservedDelta: -$reservation->quantity,
            reference: $referenceUuid,
        );

        return $item;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        if (! $this->wasActive || ! $result instanceof StockItem) {
            return;
        }

        StockCommitted::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->selling_org_uuid,
            $this->reservation->quantity,
            $this->reservation->reference_uuid,
            $result->on_hand,
            $result->available(),
        );
    }
}
