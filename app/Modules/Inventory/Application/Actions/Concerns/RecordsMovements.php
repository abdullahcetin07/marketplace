<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions\Concerns;

use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Events\StockLowStockReached;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockMovement;

/**
 * The one way this module changes a number: append a movement, then update the
 * projection it is a projection of (ADR-050).
 *
 * SHARED BY EVERY ACTION, and that is the point. If any action wrote `on_hand`
 * without a movement, the ledger would stop being the source of truth and the
 * projection's claim to be rebuildable would quietly become false — and nothing
 * would fail until somebody tried to explain a discrepancy months later. One
 * path means that cannot happen by accident.
 *
 * BOTH WRITES, ONE TRANSACTION. The caller owns the transaction (every action
 * here is a `BaseAction` with one) and must already hold the row lock; this only
 * refuses to be the place where the two writes drift apart.
 *
 * THE LOW-STOCK EDGE IS DETECTED HERE, for the same reason: every movement can
 * cross the line, so detecting it in each action would mean four copies of one
 * edge-triggering rule and four chances to get it subtly different.
 */
trait RecordsMovements
{
    /**
     * Apply a signed change to a locked pool.
     *
     * @param  StockItem  $item  MUST already be row-locked by the caller.
     * @return StockMovement the ledger row, so the action can put its uuid on an
     *                       event
     */
    protected function recordMovement(
        StockItem $item,
        StockMovementType $type,
        int $onHandDelta,
        int $reservedDelta = 0,
        ?string $reference = null,
        ?string $note = null,
    ): StockMovement {
        $movement = StockMovement::query()->create([
            'stock_item_id' => $item->getKey(),
            'type' => $type,
            'on_hand_delta' => $onHandDelta,
            'reserved_delta' => $reservedDelta,
            'reference' => $reference,
            'note' => $note,
        ]);

        /*
        | The projection. Clamped at zero on both numbers — the unsigned columns
        | would refuse a negative anyway (on Postgres), but a refusal here would
        | surface as a constraint violation rather than as the arithmetic being
        | wrong, and the callers' own guards are what should have caught it.
        */
        $item->forceFill([
            'on_hand' => max(0, $item->on_hand + $onHandDelta),
            'reserved' => max(0, $item->reserved + $reservedDelta),
        ])->save();

        $this->signalLowStock($item);

        return $movement;
    }

    /**
     * Fire the low-stock warning on the movement that CROSSES the line, and
     * re-arm it on the way back up (§3.3).
     *
     * EDGE-TRIGGERED, NOT LEVEL-TRIGGERED, and the stored `low_stock_notified`
     * flag is the whole mechanism — comparing availability before and after
     * would also work, but only for a single movement, and would re-notify the
     * moment two movements straddled the line in one request. Firing on every
     * movement while stock stayed low would train the seller to ignore the one
     * notification that matters — the same outcome as never sending it.
     *
     * The event is dispatched here rather than deferred to `after()` like every
     * other side effect in this codebase, and that is a deliberate exception: it
     * is a consequence of the movement, computed from state only this method
     * holds, and the actions that call it each already emit their own event in
     * `after()`. A low-stock signal escaping from a rolled-back transaction is
     * the one risk, and it is smaller than four actions re-deriving the edge.
     */
    private function signalLowStock(StockItem $item): void
    {
        $threshold = $item->low_stock_threshold;

        if ($threshold === null) {
            return;
        }

        $availableNow = $item->available();

        // Climbed back above the line: re-arm, so the next fall notifies again.
        if ($availableNow > $threshold) {
            if ($item->low_stock_notified) {
                $item->forceFill(['low_stock_notified' => false])->save();
            }

            return;
        }

        // At or below, and already told: stay quiet.
        if ($item->low_stock_notified) {
            return;
        }

        $item->forceFill(['low_stock_notified' => true])->save();

        StockLowStockReached::dispatch(
            $item->getKey(),
            $item->uuid,
            $item->variant_uuid,
            $item->product_uuid,
            $item->selling_org_uuid,
            $availableNow,
            $threshold,
        );
    }
}
