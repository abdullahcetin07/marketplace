<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Models\User;
use App\Modules\Order\Application\Actions\RequestOrderCancellationAction;
use App\Modules\Order\Domain\Models\CancellationRequest;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Presentation\Requests\RequestCancellationRequest;
use App\Modules\Order\Presentation\Resources\CancellationRequestResource;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The buyer's half of the cancellation exchange (ADR-065, C2).
 *
 * **TWO ROUTES, KEYED ON THE ORDER**, like the shipment and return endpoints
 * beside them: the customer holds an order uuid and nothing else, and putting a
 * request uuid on a public surface would be one more identifier to scope and one
 * more to probe. There is exactly one request worth showing per order — the
 * latest — so the singular route needs no id to disambiguate.
 *
 * **THE POST IS 201 AND IT IS NOT A CANCELLATION.** It creates a `pending`
 * request; the order is untouched, no money moves, and the storefront must say
 * "satıcı onayında" rather than confirming something that has not happened
 * (ADR-065 names this as the feature's cost). A client that reads 201 as
 * "cancelled" is the one misreading this API can produce, which is why the
 * resource leads with `status`.
 *
 * **THE GET 404s WHEN NOBODY HAS ASKED**, rather than answering an empty body or
 * a null. "There is no request" and "here is a request that does not exist" are
 * different sentences, and only one of them is a resource.
 *
 * THE SELLER'S SIDE IS NOT HERE. Approve and reject live in the seller panel, and
 * deliberately not as a branch on these routes: they are the two halves of an
 * argument, and one surface serving both is one where either can act as the other.
 *
 * @see docs/modules/Order.md §3.3
 */
final class CancellationRequestController extends BaseController
{
    public function __construct(private readonly RequestOrderCancellationAction $request) {}

    /**
     * POST /api/v1/orders/{order}/cancellation-request
     */
    public function store(RequestCancellationRequest $request, string $order): JsonResponse
    {
        $model = $this->owned($order);

        // The same ability the buyer's own outright cancel is checked against is
        // NOT reused: that one is about walking away from an unpaid order. This
        // is about asking somebody else to undo a paid one.
        $this->authorize('requestCancellation', $model);

        $created = $this->request->run($model, (int) $this->actor()->getKey(), $request->reason());

        return $this->created(new CancellationRequestResource($created));
    }

    /**
     * GET /api/v1/orders/{order}/cancellation-request
     */
    public function show(string $order): JsonResponse
    {
        $model = $this->owned($order);

        $this->authorize('view', $model);

        $request = CancellationRequest::query()
            ->forOrder($model->uuid)
            ->latest('id')
            ->first();

        if ($request === null) {
            // Nobody has asked. Not an empty resource — there is no resource.
            throw new NotFoundHttpException;
        }

        return $this->ok(new CancellationRequestResource($request));
    }

    /**
     * The acting customer's own order.
     *
     * BY SHAPE FIRST (ADR-059). `orders.uuid` and `cancellation_requests.order_uuid`
     * are native uuid columns on PostgreSQL, so a non-uuid segment would be
     * SQLSTATE[22P02] — a 500 on a button the customer taps — while SQLite quietly
     * returns nothing. The trap, thirteenth watch.
     *
     * NOT FOUND FOR "not yours", exactly as every other public surface here
     * answers: the policy would say the same, and resolving to null first means a
     * prober cannot tell a stranger's order from one that does not exist.
     */
    private function owned(string $orderUuid): Order
    {
        if (! PublicKey::looksLikeUuid($orderUuid)) {
            throw new NotFoundHttpException;
        }

        $order = Order::query()
            ->where('uuid', $orderUuid)
            ->where('customer_id', $this->actor()->getKey())
            ->first();

        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return $order;
    }

    private function actor(): User
    {
        $actor = current_actor();

        if (! $actor instanceof User) {
            throw new NotFoundHttpException;
        }

        return $actor;
    }
}
