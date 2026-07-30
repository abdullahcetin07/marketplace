<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Modules\Order\Domain\Contracts\OrderRepositoryContract;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Events\OrderPlaced;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Support\Collection;

/**
 * Place a whole purchase: COMMIT every reservation (ADR-054, §3.2).
 *
 * THE SECOND HALF OF THE TWO-STEP. Checkout held the units; this is where they
 * leave the seller's shelf. After it, each order is `AwaitingPayment` and the
 * customer owes for what they took.
 *
 * IT ACTS ON THE CHECKOUT GROUP, NOT ON ONE ORDER (ADR-052). The customer made
 * one purchase; placing half of it is not a state anybody asked for. So the group
 * is LOCKED, every order in it is committed, and a double-submitted "place"
 * button waits and then finds the work already done.
 *
 * WHY THE LOCK, given that Inventory's primitives are idempotent: they protect the
 * STOCK, and nothing else protects the order rows or the events. Without it, two
 * concurrent placements would both flip the status and both dispatch
 * `OrderPlaced` — and a future Payment subscribing to that would open two charges
 * for one purchase.
 *
 * PLACEMENT COMMITS ONLY UNTIL PAYMENT EXISTS (ADR-054). When Payment ships, the
 * commit moves to payment-success and placement merely holds — the reservation
 * window Inventory was built for. That is an additive change here, not a
 * reshaping, which is the whole reason the two-step exists now.
 *
 * THE COST IS ACCEPTED AND STATED (ADR-054): stock leaves with no money taken, so
 * a placed-but-never-paid order consumes it until somebody cancels. The
 * alternative — no commit until a module that does not exist — leaves every
 * order's stock in limbo.
 *
 * @see docs/modules/Order.md §3.2
 */
final class PlaceOrderAction extends BaseAction
{
    /**
     * @var array<int, Order>
     */
    private array $placed = [];

    public function __construct(
        private readonly OrderRepositoryContract $orders,
        private readonly InventoryReservationContract $reservations,
    ) {}

    /**
     * @return array<int, Order>
     */
    public function handle(mixed ...$arguments): array
    {
        /** @var string $checkoutGroupUuid */
        $checkoutGroupUuid = $arguments[0];

        // LOCKED for the duration — see the class docblock on why idempotent
        // stock primitives are not enough on their own.
        $orders = $this->orders->lockCheckoutGroup($checkoutGroupUuid);

        if ($orders->isEmpty()) {
            throw OrderException::checkoutGroupNotPlaceable($checkoutGroupUuid);
        }

        $placeable = $orders->filter(
            fn (Order $order): bool => $order->status === OrderStatus::Pending,
        );

        if ($placeable->isEmpty()) {
            /*
            | Nothing left to place. Distinct from a per-order `invalidTransition`
            | because this is asked of a GROUP: a double submit must not
            | half-place a purchase, and "already completed" is what the customer
            | needs to hear.
            */
            throw OrderException::checkoutGroupNotPlaceable($checkoutGroupUuid);
        }

        $this->placed = [];

        foreach ($placeable as $order) {
            $this->commit($order);

            $order->forceFill([
                'status' => OrderStatus::AwaitingPayment,
                'placed_at' => now(),
            ])->save();

            $this->placed[] = $order;
        }

        return $this->placed;
    }

    /**
     * Turn every one of this order's holds into a sale.
     *
     * PER LINE, with the reference rebuilt from the order and the line's variant —
     * the same string checkout reserved with, derived rather than stored
     * (@see Order::reservationReferenceFor()). Committing with a different string
     * would leave the hold standing forever and take the stock anyway.
     *
     * `commit()` is idempotent on the reference, so a retry that got half way
     * through finishes the rest without double-decrementing.
     */
    private function commit(Order $order): void
    {
        foreach ($order->lines as $line) {
            $this->reservations->commit($order->reservationReferenceFor($line->variant_uuid));
        }
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var array<int, Order> $orders */
        $orders = $result;

        foreach ($orders as $order) {
            /*
            | PER ORDER, not per group (§6): everything downstream of placement is
            | per seller — the seller's notification, the fulfilment, and later the
            | commission and the payout. Payment will subscribe to this one.
            |
            | It carries the seller and the totals so a consumer that may not
            | import Order can act without reading anything back.
            */
            OrderPlaced::dispatch(
                (int) $order->getKey(),
                $order->uuid,
                $order->order_number,
                $order->checkout_group_uuid,
                (int) $order->customer_id,
                $order->customer_uuid,
                $order->selling_org_uuid,
                $order->store_uuid,
                $order->grand_total_minor,
                $order->tax_total_minor,
                $order->currency->code,
            );
        }
    }
}
