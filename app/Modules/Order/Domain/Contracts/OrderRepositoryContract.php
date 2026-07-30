<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Contracts;

use App\Modules\Order\Domain\Models\Order;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for orders.
 *
 * THE VOCABULARY IS THE MODULE'S GRAIN. Three of these five methods exist only
 * because a purchase is N orders (ADR-052): a checkout group is placed together,
 * cancelled together and displayed together, so "the orders of this group" is a
 * first-class question rather than a filter someone remembers to apply.
 *
 * `lockCheckoutGroup()` IS THE CONCURRENCY ONE. Placing a group commits every
 * order's reservation; a double-submitted "place" button must not commit twice,
 * and idempotent Inventory primitives protect the stock but not the order rows.
 *
 * @see App\Modules\Order\Infrastructure\Repositories\OrderRepository
 */
interface OrderRepositoryContract
{
    public function findByUuid(string $uuid): ?Order;

    /**
     * Every order of one purchase, in creation order (ADR-052).
     *
     * @return Collection<int, Order>
     */
    public function forCheckoutGroup(string $checkoutGroupUuid): Collection;

    /**
     * The same, WITH ROW LOCKS, for placing or cancelling a whole purchase.
     *
     * MUST be called inside a transaction — a lock outside one is released
     * immediately and buys nothing. Same discipline as Inventory's
     * `lockForUpdate()`, applied to the group rather than the pool.
     *
     * @return Collection<int, Order>
     */
    public function lockCheckoutGroup(string $checkoutGroupUuid): Collection;

    /**
     * Pending orders whose reservation window has run out (§3.3).
     *
     * BOUNDED, because this feeds a job that runs forever against a table that
     * only grows: a sweep that tried to release every expired order in one pass
     * would eventually hold a transaction open for minutes.
     *
     * @return Collection<int, Order>
     */
    public function expiredPending(int $limit = 100): Collection;

    /**
     * Whether a customer already has an order for this uuid — the ownership check
     * every customer-facing read makes before it reads.
     */
    public function belongsToCustomer(string $orderUuid, int $customerId): bool;
}
