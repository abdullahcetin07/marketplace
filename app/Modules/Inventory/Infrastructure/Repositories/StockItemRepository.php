<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Repositories;

use App\Modules\Inventory\Domain\Contracts\StockItemRepositoryContract;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\StockReservation;
use Illuminate\Database\Eloquent\Collection;

/**
 * Stock's read and lock vocabulary.
 *
 * NO EAGER LOADS TO DECLARE, and that is a property of the module rather than an
 * oversight. A stock pool has exactly two relations — its own ledger and its own
 * reservations — and both are loaded deliberately, on the one screen that shows
 * them, because a seller's stock list must not drag a thousand movements behind
 * it. Everything else Inventory references lives in another bounded context and
 * is held as a uuid (ADR-040), so there is no relation to forget.
 *
 * @see App\Modules\Inventory\Domain\Contracts\StockItemRepositoryContract
 */
final class StockItemRepository implements StockItemRepositoryContract
{
    public function findByUuid(string $uuid): ?StockItem
    {
        return StockItem::query()->where('uuid', $uuid)->first();
    }

    public function findFor(string $sellingOrgUuid, string $variantUuid): ?StockItem
    {
        return StockItem::query()
            ->forSellingOrg($sellingOrgUuid)
            ->forVariant($variantUuid)
            ->first();
    }

    /**
     * THE LOCK EVERY WRITE GOES THROUGH (§3.4).
     *
     * `lockForUpdate()` holds the row until the surrounding transaction ends, so
     * a second reserve for the same pool waits rather than reading a number that
     * is about to change. Without it, two checkouts both read "1 available" and
     * both succeed — the oversell this module exists to prevent.
     *
     * SQLite (the suite) serialises writes anyway, so the lock is a no-op there
     * and the concurrency test proves the SEQUENCE rather than true parallelism.
     * Postgres is where it does real work.
     */
    public function lockForUpdate(string $sellingOrgUuid, string $variantUuid): ?StockItem
    {
        return StockItem::query()
            ->forSellingOrg($sellingOrgUuid)
            ->forVariant($variantUuid)
            ->lockForUpdate()
            ->first();
    }

    public function lockForReservation(StockReservation $reservation): ?StockItem
    {
        return StockItem::query()
            ->whereKey($reservation->stock_item_id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<int, int>  $organizationIds
     * @return Collection<int, StockItem>
     */
    public function forOrganizations(array $organizationIds): Collection
    {
        // A member of nothing gets nothing. `whereIn` on an empty array already
        // yields no rows, but stating it means the tenancy guarantee does not
        // rest on remembering that.
        if ($organizationIds === []) {
            return new Collection;
        }

        return StockItem::query()
            ->whereIn('selling_org_id', $organizationIds)
            ->orderByDesc('id')
            ->get();
    }

    public function findReservation(string $referenceUuid): ?StockReservation
    {
        return StockReservation::query()->where('reference_uuid', $referenceUuid)->first();
    }

    /**
     * @return Collection<int, StockMovement>
     */
    public function movementsFor(StockItem $item, int $limit = 100): Collection
    {
        return StockMovement::query()
            ->where('stock_item_id', $item->getKey())
            // Newest first, and bounded: the ledger grows without limit by
            // design (ADR-050), so a history screen reads a window of it.
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
