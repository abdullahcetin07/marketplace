<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Contracts;

use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\StockReservation;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for stock.
 *
 * `lockForUpdate()` IS THE ONE METHOD THAT MATTERS. Every write in this module
 * goes through it, because the race it prevents — two checkouts each reading
 * "1 available" and each reserving it — is the entire reason Inventory exists
 * as a separate authority rather than a column on the Offer.
 *
 * The tenancy vocabulary (`forOrganizations`) is what the seller panel's scope
 * wall reads (ADR-030); an empty id list yields an empty collection, never
 * everyone's stock.
 *
 * @see App\Modules\Inventory\Infrastructure\Repositories\StockItemRepository
 */
interface StockItemRepositoryContract
{
    public function findByUuid(string $uuid): ?StockItem;

    /**
     * The pool for one (org, variant), or null when the seller never listed it.
     *
     * Null is an ordinary answer, not an error: the buy box asks about variants
     * nobody sells all the time.
     */
    public function findFor(string $sellingOrgUuid, string $variantUuid): ?StockItem;

    /**
     * The same lookup, WITH A ROW LOCK, for a write.
     *
     * MUST be called inside a transaction — a lock outside one is released
     * immediately and buys nothing. This is what makes "check availability then
     * decrement" atomic instead of a race with a comfortable-looking gap in the
     * middle.
     */
    public function lockForUpdate(string $sellingOrgUuid, string $variantUuid): ?StockItem;

    /**
     * The pool a reservation is held against, locked.
     *
     * Release and commit start from a reference, not from a variant, so they
     * need the lock reached the other way round.
     */
    public function lockForReservation(StockReservation $reservation): ?StockItem;

    /**
     * Every pool belonging to any of these organizations — the seller panel's
     * scope (ADR-030).
     *
     * @param  array<int, int>  $organizationIds
     * @return Collection<int, StockItem>
     */
    public function forOrganizations(array $organizationIds): Collection;

    /**
     * A reservation by the CALLER's key — how release and commit find what to
     * act on, and how a retry finds that it has already acted.
     */
    public function findReservation(string $referenceUuid): ?StockReservation;

    /**
     * A pool's ledger, newest first — the seller's movement history.
     *
     * @return Collection<int, StockMovement>
     */
    public function movementsFor(StockItem $item, int $limit = 100): Collection;
}
