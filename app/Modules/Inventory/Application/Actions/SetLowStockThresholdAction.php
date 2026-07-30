<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Inventory\Domain\DTOs\SetLowStockThresholdDTO;
use App\Modules\Inventory\Domain\Models\StockItem;

/**
 * The line below which a seller wants to hear about a pool (§3.3).
 *
 * NO MOVEMENT, because nothing moved. This is the only write in the module that
 * does not touch the ledger, and that is correct: the ledger records changes to
 * COUNTS, and a warning threshold is a preference. Recording it as a movement
 * would put rows with two zero deltas into the one place a seller goes to
 * understand where their stock went.
 *
 * IT RE-ARMS THE WARNING. Lowering a threshold below current availability, or
 * clearing it, must clear `low_stock_notified` — otherwise a seller who raises
 * the line later never hears about the crossing, because the flag still says
 * "already told them" about a line that no longer exists.
 *
 * NULL CLEARS IT. "Stop telling me" is a real request, and distinct from a
 * threshold of zero, which means "tell me when I actually run out".
 */
final class SetLowStockThresholdAction extends BaseAction
{
    public function handle(mixed ...$arguments): StockItem
    {
        /** @var StockItem $item */
        $item = $arguments[0];
        /** @var SetLowStockThresholdDTO $data */
        $data = $arguments[1];

        $threshold = $data->threshold === null ? null : max(0, $data->threshold);

        $item->forceFill([
            'low_stock_threshold' => $threshold,
            // Re-arm: the old notification was about a line that may no longer
            // apply, and a stale flag silences the next real crossing.
            'low_stock_notified' => false,
        ])->save();

        return $item;
    }
}
