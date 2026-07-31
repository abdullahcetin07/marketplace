<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Models\User;
use App\Modules\Order\Application\Actions\CancelOrderAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Domain\Contracts\OrderRepositoryContract;
use App\Modules\Order\Domain\DTOs\CancelOrderDTO;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Presentation\Requests\CancelOrderRequest;
use App\Modules\Order\Presentation\Requests\CheckoutRequest;
use App\Modules\Order\Presentation\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Checkout, placement and the customer's own orders (§4).
 *
 * CHECKOUT AND PLACE ARE TWO CALLS, NOT ONE, because they are two different
 * commitments (ADR-054). `POST /checkout` reserves the stock and produces the
 * orders — the customer now knows exactly what they owe and to whom. `POST
 * /checkout/{group}/place` commits it. Between them sits the reservation window
 * a payment step will eventually occupy, and the shape does not change when it
 * does.
 *
 * BOTH RETURN THE WHOLE CHECKOUT GROUP, never a single order, because a purchase
 * is N orders (ADR-052) and a client that received one would have to discover the
 * rest. `GET /orders` is likewise a flat list carrying `checkout_group_id` on
 * every row, so the client groups rather than the API guessing at a nesting.
 *
 * EVERY READ IS SCOPED TO THE AUTHENTICATED CUSTOMER **and** policy-checked. The
 * scope makes another customer's order unreachable; the policy is what a future
 * surface that reaches wider still has to satisfy. Belt and braces, on the one
 * resource that records what somebody bought and where it is being sent.
 *
 * @see docs/modules/Order.md §4
 */
final class CustomerOrderController extends BaseController
{
    public function __construct(
        private readonly OrderRepositoryContract $orders,
        private readonly CheckoutAction $checkout,
        private readonly PlaceOrderAction $place,
        private readonly CancelOrderAction $cancel,
    ) {}

    /**
     * POST /checkout
     *
     * Reserves the stock and splits the basket. 201, because orders were created
     * — even though nothing is placed yet.
     */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        $customer = $this->customer();

        $orders = $this->checkout->run(
            (int) $customer->getKey(),
            $customer->uuid,
            $request->toDto(),
        );

        return $this->created(
            OrderResource::collection(array_map(
                fn (Order $order): Order => $order->load(['lines', 'currency']),
                $orders,
            )),
            null,
            // The handle the client needs for the very next call. Stated in the
            // envelope rather than left to be read off the first row.
            ['checkout_group_id' => $orders[0]->checkout_group_uuid],
        );
    }

    /**
     * POST /checkout/{group}/place
     *
     * Commits every reservation in the group → `AwaitingPayment` (ADR-054).
     */
    public function place(string $group): JsonResponse
    {
        // Ownership BEFORE the action, and per order: `PlaceOrderAction` acts on a
        // group uuid, which is not by itself proof that the group is this
        // customer's.
        $orders = $this->ownedGroup($group);

        foreach ($orders as $order) {
            $this->authorize('place', $order);
        }

        $this->place->run($group);

        return $this->ok(OrderResource::collection(
            $this->orders->forCheckoutGroup($group),
        ));
    }

    /**
     * GET /orders
     */
    public function index(): JsonResponse
    {
        $orders = Order::query()
            ->with(['lines', 'currency'])
            ->forCustomer($this->customerId())
            ->orderByDesc('id')
            ->paginate($this->perPage());

        return $this->paginated($orders, OrderResource::collection($orders->items()));
    }

    /**
     * GET /orders/{order}
     */
    public function show(string $order): JsonResponse
    {
        $model = $this->find($order);

        $this->authorize('view', $model);

        return $this->ok(new OrderResource($model));
    }

    /**
     * POST /orders/{order}/cancel
     *
     * PER ORDER, not per group, and deliberately: a customer who wants one
     * seller's half of a purchase cancelled should not have to abandon the other
     * seller's — each order is independently fulfilled, so each is independently
     * cancellable (ADR-052).
     */
    public function cancel(CancelOrderRequest $request, string $order): JsonResponse
    {
        $model = $this->find($order);

        $this->authorize('cancel', $model);

        $this->cancel->run($model, new CancelOrderDTO(
            // DERIVED FROM THE ACTOR, never accepted from the payload: a client
            // that could claim `seller` or `expiry` would misattribute its own
            // cancellation.
            cancelledBy: CancelOrderDTO::BY_CUSTOMER,
            reason: $request->validated('reason'),
        ));

        return $this->ok(new OrderResource($model->fresh()->load(['lines', 'currency'])));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Order>
     */
    private function ownedGroup(string $group): \Illuminate\Database\Eloquent\Collection
    {
        $orders = $this->orders->forCheckoutGroup($group);

        if ($orders->isEmpty()) {
            throw new NotFoundHttpException;
        }

        return $orders;
    }

    /**
     * Scoped to the customer, so another customer's order uuid is a 404 rather
     * than a 403 — the difference would confirm the order exists.
     */
    private function find(string $uuid): Order
    {
        $order = Order::query()
            ->with(['lines', 'currency'])
            ->forCustomer($this->customerId())
            ->where('uuid', $uuid)
            ->first();

        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return $order;
    }

    private function customer(): User
    {
        /** @var User $user */
        $user = current_actor();

        return $user;
    }

    private function customerId(): int
    {
        return (int) $this->customer()->getKey();
    }
}
