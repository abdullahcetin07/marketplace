<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Models\User;
use App\Modules\Order\Application\Actions\CreateReturnRequestAction;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\ReturnRequest;
use App\Modules\Order\Presentation\Requests\CreateReturnRequestRequest;
use App\Modules\Order\Presentation\Resources\ReturnRequestResource;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The buyer's half of the return exchange (ADR-073).
 *
 * **IT REPLACED AN ENDPOINT THAT MOVED MONEY.** `POST /orders/{order}/return`
 * refunded on the spot (ADR-064: the window was the approval); this writes a
 * `requested` row and returns 201, and **the storefront must say "satıcı
 * onayında"** rather than confirming a refund that has not happened. A client
 * reading 201 as "money is on its way" is the one misreading this API can
 * produce, which is why the resource leads with `status`.
 *
 * **`GET /orders/{order}/return` SURVIVES UNCHANGED**, in Payment, and the two are
 * not redundant. That one answers "what may still go back, priced, and until
 * when" — the form's inputs. This one answers "what did I ask for and what came of
 * it" — the form's outcome. Merging them would put a live price quote and a
 * historical record in one payload, and the day a line was partly refunded they
 * would disagree.
 *
 * **TWO ROUTES, KEYED ON THE ORDER**, like every other customer surface here: the
 * buyer holds an order uuid and nothing else, and a request uuid on a public
 * surface would be one more thing to scope and one more to probe.
 *
 * THE GET 404s WHEN NOBODY HAS ASKED, rather than an empty body — "there is no
 * request" and "here is a request that does not exist" are different sentences.
 *
 * THE SELLER'S THREE ANSWERS ARE NOT HERE. They live in the panel, deliberately
 * not as a branch on these routes: they are the two halves of a conversation, and
 * one surface serving both is one where either can act as the other.
 *
 * @see docs/modules/Order.md §3.6
 */
final class ReturnRequestController extends BaseController
{
    public function __construct(private readonly CreateReturnRequestAction $create) {}

    /**
     * POST /api/v1/orders/{order}/return-request
     */
    public function store(CreateReturnRequestRequest $request, string $order): JsonResponse
    {
        $model = $this->owned($order);

        $this->authorize('requestReturn', $model);

        $created = $this->create->run(
            $model,
            $request->quantities(),
            (int) $this->actor()->getKey(),
            $request->reason(),
        );

        // 201 = "talep alındı", NOT "iade edildi". @see the class docblock.
        return $this->created(new ReturnRequestResource($created));
    }

    /**
     * GET /api/v1/orders/{order}/return-request
     */
    public function show(string $order): JsonResponse
    {
        $model = $this->owned($order);

        $this->authorize('view', $model);

        $request = ReturnRequest::query()
            ->forOrder($model->uuid)
            /*
            | THE LATEST, NOT THE OPEN ONE. A completed return is exactly what the
            | buyer wants to see on the order page afterwards — "iade tamamlandı,
            | ücret iade edildi" — and showing only open requests would make the
            | outcome vanish the moment it arrived.
            */
            ->latest('id')
            ->first();

        if ($request === null) {
            throw new NotFoundHttpException;
        }

        return $this->ok(new ReturnRequestResource($request));
    }

    /**
     * The acting customer's own order.
     *
     * BY SHAPE FIRST (ADR-059). `orders.uuid` and `return_requests.order_uuid` are
     * native uuid columns on PostgreSQL, so a non-uuid segment would be
     * SQLSTATE[22P02] — a 500 on a button the customer taps — while SQLite quietly
     * returns nothing. The trap, fourteenth watch.
     *
     * NOT FOUND FOR "not yours", exactly as every other public surface here
     * answers: resolving to null first means a prober cannot tell a stranger's
     * order from one that does not exist.
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
