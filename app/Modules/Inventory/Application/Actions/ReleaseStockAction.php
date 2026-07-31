<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Inventory\Application\Actions\Concerns\RecordsMovements;
use App\Modules\Inventory\Domain\Contracts\StockItemRepositoryContract;
use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Events\StockReleased;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockReservation;

/**
 * Give a hold back — an abandoned or cancelled checkout (§0.4).
 *
 * `reserved` FALLS AND NOTHING ELSE MOVES. The units were never gone, so nothing
 * is "returned" physically; availability simply rises again and the storefront
 * can offer them to the next shopper.
 *
 * A REPEAT IS A NO-OP, NOT AN ERROR. A reservation that is already released or
 * committed is terminal, and acting on it again would decrement `reserved` a
 * second time — which is how a cart-timeout job firing twice would silently
 * hand a seller phantom availability. Distinct from a reference that never
 * existed, which IS an error worth surfacing: that is a caller bug, not a retry.
 *
 * @see docs/modules/Inventory.md §3.2
 */
final class ReleaseStockAction extends BaseAction
{
    use RecordsMovements;

    private bool $wasActive = false;

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

        if (! $reservation->isActive()) {
            // Already finished, either way. Idempotent by design (§3.2).
            return $this->items->lockForReservation($reservation);
        }

        $item = $this->items->lockForReservation($reservation);

        if ($item === null) {
            throw InventoryException::reservationNotFound($reference);
        }

        $this->wasActive = true;

        $reservation->forceFill([
            'status' => ReservationStatus::Released,
            'released_at' => now(),
        ])->save();

        $this->recordMovement(
            $item,
            StockMovementType::Released,
            onHandDelta: 0,
            reservedDelta: -$reservation->quantity,
            reference: $reference,
        );

        return $item;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        if (! $this->wasActive || ! $result instanceof StockItem) {
            return;
        }

        StockReleased::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->selling_org_uuid,
            $this->reservation->quantity,
            $this->reservation->reference,
            $result->available(),
        );
    }
}
