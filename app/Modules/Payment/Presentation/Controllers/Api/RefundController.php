<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Payment\Application\Actions\RefundPaymentAction;
use App\Modules\Payment\Domain\DTOs\RefundRequestDTO;
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
 * IT TAKES ORDERS, NOT AN AMOUNT. @see `RefundRequestDTO` — "partially refunded"
 * on this platform means some of the sellers' orders in the basket.
 *
 * THE RESPONSE IS THE PAYMENT PLUS ITS REFUNDS, because the interesting fact is
 * the pair: the payment's new status says whether anything is left, and the rows
 * say what went back and under which PSP reference.
 *
 * @see docs/modules/Payment.md §8
 */
final class RefundController extends BaseController
{
    public function __construct(private readonly RefundPaymentAction $refund) {}

    /**
     * POST /api/v1/admin/payments/{payment}/refund
     */
    public function store(RefundPaymentRequest $request, string $payment): JsonResponse
    {
        $model = $this->resolve($payment);

        $this->authorize('refund', $model);

        $actor = current_actor();

        $refunded = $this->refund->run(new RefundRequestDTO(
            paymentUuid: $model->uuid,
            orderUuids: $request->orderUuids(),
            reason: $request->reason(),
            actorId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
        ));

        return $this->ok([
            'payment' => new PaymentResource($refunded->load('currency')),
            'refunds' => PaymentRefundResource::collection($this->refundsFor($refunded)),
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
