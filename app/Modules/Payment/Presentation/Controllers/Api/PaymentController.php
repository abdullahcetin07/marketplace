<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Payment\Application\Actions\InitiatePaymentAction;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Presentation\Resources\PaymentResource;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The buyer's two payment endpoints (Payment.md §3).
 *
 * BOTH ARE CUSTOMER-SCOPED AND NEITHER TAKES AN AMOUNT. The total is summed from
 * the orders the group already holds — a client-supplied amount is the oldest
 * vulnerability in e-commerce, and there is no field here that could carry one.
 *
 * `initiate` RETURNS A TOKEN, NOT A REDIRECT. The storefront embeds PayTR's iframe
 * with it, so the card and the 3-D Secure step never touch this application.
 *
 * `show` EXISTS FOR THE MOMENT AFTER THE REDIRECT. PayTR sends the browser back to
 * a result page, and that page needs to know what actually happened — which the
 * SERVER learned from the callback, not from the redirect. So the storefront asks
 * here rather than trusting a query string it was handed.
 *
 * @see docs/modules/Payment.md §3
 */
final class PaymentController extends BaseController
{
    public function __construct(private readonly InitiatePaymentAction $initiate) {}

    /**
     * POST /api/v1/checkout/{group}/pay
     */
    public function store(Request $request, string $group): JsonResponse
    {
        $actor = current_actor();

        /*
        | RESOLVED BY SHAPE BEFORE ANY QUERY (ADR-059). A checkout group is a uuid,
        | and `where('checkout_group_uuid', 'nonsense')` on PostgreSQL is
        | SQLSTATE[22P02] — a 500 rather than a 404. Five modules have now met that
        | trap; this is the guard, applied before Payment can join them.
        */
        if (! PublicKey::looksLikeUuid($group)) {
            throw PaymentException::groupNotFound($group);
        }

        /*
        | **THE POINTS THE SHOPPER CHOSE, OR NONE** (ADR-084). Optional, so every
        | existing caller keeps working unchanged; absent means zero, which also
        | RELEASES a hold from a previous attempt — unticking "Puanını kullan" and
        | paying again must not leave the balance occupied.
        |
        | Not validated beyond its shape: asking for more points than the balance
        | is not an error, the port clamps. A slider is allowed to be optimistic.
        */
        $result = $this->initiate->run(
            $group,
            (string) $request->ip(),
            max(0, (int) $request->integer('points')),
        );

        /** @var Payment $payment */
        $payment = $result['payment'];

        // The customer who owns the orders is resolved inside the action from the
        // group itself; this is the second half of the check — the signed-in actor
        // must be that customer.
        if ($actor === null || $payment->customer_id !== $actor->getKey()) {
            throw PaymentException::groupNotFound($group);
        }

        return $this->ok([
            'payment_id' => $payment->uuid,
            /*
            | **`paid` IS AN ANSWER, NOT AN INFERENCE** (ADR-084). When points cover
            | the whole basket there is no card charge and no iFrame, so the
            | storefront must send the shopper to the result page instead of
            | opening one. It could have read that off a null `iframe_token` — and
            | that would be wrong the first time a gateway failure also returns
            | null. The payment says what it is.
            */
            'paid' => $payment->status === PaymentStatus::Paid,
            'status' => $payment->status->value,
            // Null when there is nothing to open: paid with points, so no iFrame.
            'iframe_token' => $result['token'],
            /*
            | Money as decimal strings (ADR-005). `amount` is what the CARD is
            | charged — zero on the points-only path — and `discount` is what the
            | points covered. The two add up to the basket the shopper agreed to.
            */
            'amount' => $this->decimal($payment->amount_minor),
            'discount' => $this->decimal($payment->discount_minor),
            // A count, not money: points are things.
            'points_spent' => $payment->points_spent,
            'currency' => $payment->currency->code,
        ]);
    }

    /**
     * GET /api/v1/payments/{payment}
     */
    public function show(string $payment): JsonResponse
    {
        $actor = current_actor();

        if ($actor === null || ! PublicKey::looksLikeUuid($payment)) {
            throw PaymentException::groupNotFound($payment);
        }

        $model = Payment::query()
            ->with('currency')
            ->forCustomer((int) $actor->getKey())
            ->where('uuid', $payment)
            ->first();

        if ($model === null) {
            // "Not yours" and "does not exist" answer identically — a payment uuid
            // must not be probeable.
            throw PaymentException::groupNotFound($payment);
        }

        return $this->ok(new PaymentResource($model));
    }

    private function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
