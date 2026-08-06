<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Controllers\Api;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Payment\Application\Actions\RefundLinesAction;
use App\Modules\Payment\Application\Actions\RefundPaymentAction;
use App\Modules\Payment\Domain\DTOs\RefundRequestDTO;
use App\Modules\Payment\Domain\DTOs\ReturnRequestDTO;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Presentation\Requests\RefundPaymentRequest;
use App\Modules\Payment\Presentation\Resources\PaymentRefundResource;
use App\Modules\Payment\Presentation\Resources\PaymentResource;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The admin's refund surface (Payment.md §8, P5).
 *
 * **THIS ENDPOINT MOVES REAL MONEY OUT**, which is the one thing no other write in
 * this module does — a payout only RECORDS a transfer a human made, and the
 * callback only records what the buyer already did. Here, a POST causes PayTR to
 * send money back. Hence: admin-only, ability-gated, uuid-shape-guarded, and
 * idempotent at the database.
 *
 * IT TAKES ORDERS OR LINES, AND NEVER AN AMOUNT. @see `RefundRequestDTO` —
 * "partially refunded" meant "some of the sellers' orders in the basket" in P5,
 * and S4 added a second granularity below it: `order_id` + `lines` refunds a
 * QUANTITY of specific lines through the same machinery the buyer's own return
 * uses. Neither shape has a field that could carry a lira figure.
 *
 * THE LINE PATH EXISTS BECAUSE S4 CREATED SOMETHING ONLY IT CAN FIX: an order
 * with a partial return is skipped by the whole-order path (refunding it again
 * would refund the returned unit twice), so without this it would be stuck partly
 * refunded with no way to finish it.
 *
 * THE RESPONSE IS THE PAYMENT PLUS ITS REFUNDS, because the interesting fact is
 * the pair: the payment's new status says whether anything is left, and the rows
 * say what went back and under which PSP reference.
 *
 * @see docs/modules/Payment.md §8
 */
final class RefundController extends BaseController
{
    public function __construct(
        private readonly RefundPaymentAction $refund,
        private readonly RefundLinesAction $refundLines,
        private readonly OrderQueryContract $orders,
    ) {}

    /**
     * POST /api/v1/admin/payments/{payment}/refund
     */
    public function store(RefundPaymentRequest $request, string $payment): JsonResponse
    {
        $model = $this->resolve($payment);

        $this->authorize('refund', $model);

        $actor = current_actor();
        $actorId = $actor?->getKey() === null ? null : (int) $actor->getKey();

        $quantities = $request->quantities();

        if ($quantities !== []) {
            $this->refundLines($model, $request, $quantities, $actorId);
            $model = $model->fresh()->load('currency');
        } else {
            $model = $this->refund->run(new RefundRequestDTO(
                paymentUuid: $model->uuid,
                orderUuids: $request->orderUuids(),
                reason: $request->reason(),
                actorId: $actorId,
            ));
        }

        return $this->ok([
            'payment' => new PaymentResource($model->load('currency')),
            'refunds' => PaymentRefundResource::collection($this->refundsFor($model)),
        ]);
    }

    /**
     * GET /api/v1/admin/payments/{payment}/refunds
     */
    public function index(string $payment): JsonResponse
    {
        $model = $this->resolve($payment);

        $this->authorize('view', $model);

        return $this->ok(PaymentRefundResource::collection($this->refundsFor($model)));
    }

    /**
     * The S4 half — this many of these lines, on ONE order of this payment.
     *
     * IT IS THE BUYER'S MACHINERY WITH THE BUYER'S GUARDS REMOVED, which is the
     * whole point of the split: an admin refunding the same lines must produce
     * byte-identical rows, so there is one implementation of the money and two
     * ways in. What an admin skips is ownership and the return window — a refund
     * outside the window is exactly the judgement call the window hands back to a
     * human.
     *
     * THE ORDER MUST BE IN THIS PAYMENT'S GROUP. Without that check the route
     * parameter would be decoration and an admin could refund any order through
     * any payment, which the ledger would record under the wrong charge.
     *
     * @param array<string, int> $quantities
     */
    private function refundLines(Payment $payment, RefundPaymentRequest $request, array $quantities, ?int $actorId): void
    {
        $orderUuid = (string) $request->orderUuid();

        if (! in_array($orderUuid, $this->orders->ordersForCheckoutGroup($payment->checkout_group_uuid), true)) {
            // The same answer "no such order" gets. An admin who mistyped an
            // order uuid learns nothing about whose it was.
            throw new NotFoundHttpException;
        }

        $this->refundLines->run(new ReturnRequestDTO(
            orderUuid: $orderUuid,
            quantities: $quantities,
            reason: $request->reason(),
            actorId: $actorId,
        ));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PaymentRefund>
     */
    private function refundsFor(Payment $payment): \Illuminate\Database\Eloquent\Collection
    {
        return PaymentRefund::query()
            ->with('currency')
            ->forPayment((int) $payment->getKey())
            ->latest('id')
            ->get();
    }

    /**
     * BY SHAPE FIRST (ADR-059). `payments.uuid` is a native uuid column on
     * PostgreSQL, so `where('uuid', 'not-a-uuid')` is SQLSTATE[22P02] — a 500
     * rather than a 404. The trap, seventh watch.
     */
    private function resolve(string $payment): Payment
    {
        if (! PublicKey::looksLikeUuid($payment)) {
            throw new NotFoundHttpException;
        }

        $model = Payment::query()->with('currency')->where('uuid', $payment)->first();

        if ($model === null) {
            throw new NotFoundHttpException;
        }

        return $model;
    }
}
