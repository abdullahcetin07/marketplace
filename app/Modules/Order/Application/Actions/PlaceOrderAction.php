<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Order\Domain\Contracts\OrderRepositoryContract;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Events\OrderPlaced;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\Order;

/**
 * Place a whole purchase — and KEEP the reservation held (ADR-057, §3.2).
 *
 * THIS ACTION USED TO COMMIT, and the change is the whole of ADR-057. Committing
 * at placement made the units leave the seller's shelf before anybody had paid,
 * and — worse — left a cancelled order with nothing to give back: Inventory has no
 * un-commit, and `release()` on a committed reference is a documented no-op. A
 * customer could cancel a placed order and the stock would simply be gone.
 *
 * SO PLACEMENT NOW ONLY CONFIRMS INTENT. The order moves to `AwaitingPayment`, the
 * hold stays exactly as checkout left it, and cancelling — by anyone, at any point
 * — is a plain `release` that returns the units. That is the two-step working as
 * ADR-049 designed it rather than being short-circuited by the absence of Payment.
 *
 * NOTHING COMMITS THIS SPRINT, ANYWHERE. Commit becomes Payment's: a successful
 * charge is what makes units truly leave. There is deliberately no payment gate
 * here and no money of any kind — adding one would be building Payment inside
 * Order, in the file that most invites it.
 *
 * THE COST, STATED (ADR-057): `on_hand` does not decrement until Payment exists,
 * so a placed-but-unpaid order holds its reservation indefinitely — there is no
 * sweep for it, because expiring a purchase the customer believes they have made
 * is worse than holding stock. Accepted: a unit is not sold until it is paid, and
 * a reserved unit already shows as unavailable everywhere that matters.
 *
 * IT ACTS ON THE CHECKOUT GROUP, NOT ON ONE ORDER (ADR-052). The customer made
 * one purchase; placing half of it is not a state anybody asked for. So the group
 * is LOCKED, every order in it is placed together, and a double-submitted "place"
 * button waits and then finds the work already done.
 *
 * WHY THE LOCK, given that this no longer touches Inventory at all: it protects
 * the ORDER ROWS and the events. Without it, two concurrent placements would both
 * flip the status and both dispatch `OrderPlaced` — and a future Payment
 * subscribing to that would open two charges for one purchase.
 *
 * @see docs/modules/Order.md §3.2
 */
final class PlaceOrderAction extends BaseAction
{
    /**
     * @var array<int, Order>
     */
    private array $placed = [];

    /**
     * NO `InventoryReservationContract` DEPENDENCY ANY MORE, and its absence is
     * worth noticing: since ADR-057 placing an order touches no other context at
     * all. The stock was already held at checkout and stays held; this action only
     * moves a status and announces it.
     */
    public function __construct(
        private readonly OrderRepositoryContract $orders,
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
            /*
            | NO `commit()` CALL, AND THAT IS THE POINT (ADR-057). The reservation
            | checkout took stays exactly as it is; placing only records that the
            | customer means it. Payment will be the thing that commits.
            */
            $order->forceFill([
                'status' => OrderStatus::AwaitingPayment,
                'placed_at' => now(),
            ])->save();

            $this->placed[] = $order;
        }

        return $this->placed;
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
