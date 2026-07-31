<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Inventory\Application\Actions\Concerns\RecordsMovements;
use App\Modules\Inventory\Domain\Contracts\StockItemRepositoryContract;
use App\Modules\Inventory\Domain\DTOs\ReserveStockDTO;
use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Events\StockReserved;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockReservation;

/**
 * Hold units for an in-flight checkout (§0.4, ADR-049).
 *
 * THE ACTION THIS WHOLE MODULE EXISTS FOR. Two buyers reaching the last unit at
 * the same moment is the race a stock column on the Offer could never arbitrate,
 * and the arbitration is the row lock: the pool is locked, availability is read
 * INSIDE the lock, and the second caller waits and then sees the number the first
 * one left behind. Reading availability before the lock — the obvious shape —
 * would leave exactly the gap this prevents.
 *
 * ON-HAND DOES NOT MOVE. Nothing has left the seller; the units are spoken for.
 * That distinction is the reason `reserved` is a separate number and the reason
 * the ledger records a type — a bare counter dropping by one cannot say whether
 * something sold or is merely held.
 *
 * IDEMPOTENT ON THE CALLER'S REFERENCE. A retried checkout finds its own hold and
 * succeeds without taking a second one. That is not politeness: a payment
 * provider retrying a webhook is normal, and the alternative is overselling on a
 * network blip.
 *
 * @see docs/modules/Inventory.md §3.2, §3.4
 */
final class ReserveStockAction extends BaseAction
{
    use RecordsMovements;

    private bool $alreadyHeld = false;

    private StockReservation $reservation;

    public function __construct(
        private readonly StockItemRepositoryContract $items,
    ) {}

    public function handle(mixed ...$arguments): StockItem
    {
        /** @var ReserveStockDTO $data */
        $data = $arguments[0];

        if ($data->quantity <= 0) {
            throw InventoryException::invalidQuantity($data->quantity);
        }

        /*
        | IDEMPOTENCY FIRST, before the lock and before any arithmetic. A repeat
        | of a hold that already stands must not consume a second unit, and
        | checking after would mean the second call had already changed the
        | numbers by the time it noticed.
        */
        $existing = $this->items->findReservation($data->reference);

        if ($existing !== null) {
            $this->alreadyHeld = true;
            $this->reservation = $existing;

            $item = $this->items->lockForReservation($existing);

            if ($item === null) {
                throw InventoryException::reservationNotFound($data->reference);
            }

            return $item;
        }

        $item = $this->items->lockForUpdate($data->sellingOrgUuid, $data->variantUuid);

        if ($item === null) {
            // Never listed, as distinct from sold out: a caller told the wrong
            // one of those would retry something that can never succeed.
            throw InventoryException::stockItemNotFound($data->variantUuid, $data->sellingOrgUuid);
        }

        // INSIDE the lock. This is the line that makes the whole thing work.
        if (! $item->isAvailable($data->quantity)) {
            throw InventoryException::insufficientStock(
                $data->variantUuid,
                $data->quantity,
                $item->available(),
            );
        }

        $this->reservation = StockReservation::query()->create([
            'reference' => $data->reference,
            'stock_item_id' => $item->getKey(),
            'quantity' => $data->quantity,
            'status' => ReservationStatus::Active,
        ]);

        $this->recordMovement(
            $item,
            StockMovementType::Reserved,
            onHandDelta: 0,
            reservedDelta: $data->quantity,
            reference: $data->reference,
        );

        return $item;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        // A repeat of an existing hold changed nothing, so it announces nothing —
        // a listener reindexing on every retry would be work for its own sake.
        if ($this->alreadyHeld) {
            return;
        }

        /** @var StockItem $result */
        StockReserved::dispatch(
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
