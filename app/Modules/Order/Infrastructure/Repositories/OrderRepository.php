<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Repositories;

use App\Modules\Order\Domain\Contracts\OrderRepositoryContract;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Database\Eloquent\Collection;

/**
 * The order's read and lock vocabulary.
 *
 * LINES AND CURRENCY ARE ALWAYS EAGER LOADED. An order without its lines cannot
 * be displayed, cancelled (the release walks the lines) or totalled, and without
 * its currency no amount can be rendered — so both are declared here rather than
 * remembered (strict mode makes a lazy load throw).
 *
 * @see App\Modules\Order\Domain\Contracts\OrderRepositoryContract
 */
final class OrderRepository implements OrderRepositoryContract
{
    /**
     * @var list<string>
     */
    private array $with = ['lines', 'currency'];

    public function findByUuid(string $uuid): ?Order
    {
        return Order::query()->with($this->with)->where('uuid', $uuid)->first();
    }

    /**
     * @return Collection<int, Order>
     */
    public function forCheckoutGroup(string $checkoutGroupUuid): Collection
    {
        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->with($this->with)
            ->inCheckoutGroup($checkoutGroupUuid)
            ->orderBy('id')
            ->get();

        return $orders;
    }

    /**
     * THE GROUP, LOCKED (§3.2).
     *
     * `lockForUpdate()` holds every row of the purchase until the transaction
     * ends, so a double-submitted "place" waits and then finds the orders already
     * placed rather than committing each reservation twice. Inventory's
     * primitives are idempotent on the reference and would protect the STOCK, but
     * nothing else protects the order rows or the events they raise.
     *
     * Relations are loaded AFTER the lock, deliberately: `lockForUpdate` with an
     * eager load locks only the parent rows, and loading first would mean acting
     * on lines read before the lock was taken.
     *
     * @return Collection<int, Order>
     */
    public function lockCheckoutGroup(string $checkoutGroupUuid): Collection
    {
        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->inCheckoutGroup($checkoutGroupUuid)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $orders->load($this->with);
    }

    /**
     * Placed orders whose payment window has run out (ADR-072).
     *
     * **THE SIBLING OF `expiredPending()`, AND A DIFFERENT WINDOW ENTIRELY.**
     * That one sweeps `Pending` — a basket abandoned at the address step, 30
     * minutes, ending in `Cancelled`. This one sweeps `AwaitingPayment`: the
     * order was PLACED, the customer was sent to the card form and never came
     * back, and it ends in `Expired` with the hold released.
     *
     * **IT MEASURES FROM `placed_at`, NOT `created_at`.** `created_at` is when
     * the checkout produced the row, which can be minutes before placement while
     * a shopper picks an address — measuring from it would start the payment
     * clock before the customer ever saw a card form. `placed_at` is stamped by
     * `PlaceOrderAction` at the moment the order enters `AwaitingPayment`, which
     * is exactly what this window is about.
     *
     * CAPPED, like its sibling: a sweep that tried to release everything in one
     * pass would hold a transaction open for minutes on a busy platform, and the
     * work is idempotent so the remainder waits for the next minute.
     *
     * @return Collection<int, Order>
     */
    public function awaitingPaymentExpired(int $windowMinutes, int $limit = 100): Collection
    {
        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->with($this->with)
            ->withStatus(OrderStatus::AwaitingPayment)
            ->where('placed_at', '<=', now()->subMinutes(max(1, $windowMinutes)))
            ->orderBy('placed_at')
            ->limit($limit)
            ->get();

        return $orders;
    }

    /**
     * Pending orders past the reservation window (§3.3).
     *
     * THE CUTOFF IS COMPUTED HERE, not in the job, so the sweep and
     * `Order::reservationHasExpired()` cannot disagree about what "expired" means
     * — one deciding to release a hold the other still shows as live is the worst
     * kind of drift.
     *
     * Bounded and oldest-first: the job runs forever against a table that only
     * grows, and the oldest hold is the one costing a seller the most.
     *
     * @return Collection<int, Order>
     */
    public function expiredPending(int $limit = 100): Collection
    {
        $cutoff = now()->subMinutes(
            (int) config('order.reservation.expires_after_minutes', 30),
        );

        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->with($this->with)
            ->withStatus(OrderStatus::Pending)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        return $orders;
    }

    public function belongsToCustomer(string $orderUuid, int $customerId): bool
    {
        return Order::query()
            ->where('uuid', $orderUuid)
            ->forCustomer($customerId)
            ->exists();
    }
}
