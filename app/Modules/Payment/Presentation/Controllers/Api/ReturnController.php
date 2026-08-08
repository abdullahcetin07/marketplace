<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Controllers\Api;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Core\Presentation\Support\MoneyString;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefundLine;
use App\Modules\Payment\Domain\Models\SettlementWindow;
use App\Modules\Payment\Domain\Support\RefundableLines;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;

/**
 * The buyer's return surface (S4, Payment.md §8) — **now READ-ONLY (ADR-073).**
 *
 * **THE POST THAT LIVED HERE MOVED MONEY, AND IT IS GONE.** S4 refunded on the
 * buyer's request, because ADR-064 treated the return window as the approval. For
 * physical goods that is refunding on trust: the seller is made whole never and
 * the parcel may or may not come back. The buyer's write is now
 * `POST /orders/{order}/return-request` in ORDER, which writes a request and
 * moves nothing, and the refund fires when the SELLER confirms the goods arrived.
 *
 * **THE READ STAYED HERE RATHER THAN MOVING WITH IT**, and that is deliberate:
 * every number in it — what has already gone back, what the platform will pay for
 * the rest, the last unit's remainder — is Payment's arithmetic. Order would have
 * had to ask for all of it through a port and then re-render it, which is a second
 * copy of a quote nobody needs.
 *
 * **ONE ENDPOINT, AND IT IS NOT DECORATION.** A return form has to know
 * three things the storefront cannot work out for itself: whether the window is
 * still open, how many of each line have ALREADY gone back, and what the platform
 * will actually pay for the rest. All three live here, so the form the buyer sees
 * and the arithmetic the refund performs come from one source. A storefront that
 * multiplied `unit_price × quantity` itself would disagree with the last unit's
 * remainder (@see `RefundableLines`) on exactly the orders somebody checked.
 *
 * KEYED ON THE ORDER, like the shipment endpoints beside it: the customer holds
 * an order uuid and nothing else, and putting a payment or refund identifier on a
 * public surface would be one more thing to scope and one more thing to probe.
 *
 * ONE ANSWER FOR EVERY REFUSAL — "no such order", "not yours", "never delivered",
 * "too late". @see `RequestReturnAction`, which owns the same rule for the write.
 * This controller's read repeats it rather than short-circuiting: a 404 here and
 * a 409 there for the same order would tell a prober which one it was.
 *
 * @see docs/modules/Payment.md §8
 */
final class ReturnController extends BaseController
{
    public function __construct(private readonly OrderQueryContract $orders) {}

    /**
     * GET /api/v1/orders/{order}/return — what may still go back, and until when.
     */
    public function show(string $order): JsonResponse
    {
        $customerId = $this->customerId();

        $this->assertOwned($order, $customerId);

        $window = SettlementWindow::query()->where('order_uuid', $order)->first();
        $payment = $this->paymentFor($order);
        $decimals = $payment?->currency->decimal_places ?? 2;

        $lines = [];

        foreach ($this->orders->orderLines($order) as $line) {
            $returned = PaymentRefundLine::refundedQuantityFor($line['id']);
            $remaining = max(0, $line['quantity'] - $returned);

            /*
            | PRICED AT THE FULL REMAINDER, which is what the buyer gets if they
            | send everything left in the box back. A smaller quantity is priced
            | by multiplication and needs no round trip; only the LAST unit of a
            | line carries a remainder, and it is in this figure.
            */
            $refundable = $remaining > 0 ? RefundableLines::price($line, $remaining) : null;
            $refundableMinor = $refundable === null ? 0 : $refundable->amountMinor;

            $lines[] = [
                'id' => $line['id'],
                'title' => $line['title'],
                'quantity' => $line['quantity'],
                'returned_quantity' => $returned,
                'returnable_quantity' => $remaining,
                'refundable_amount_minor' => $refundableMinor,
                'refundable_amount' => MoneyString::from($refundableMinor, $decimals),
            ];
        }

        return $this->ok([
            'order_id' => $order,
            // The two facts the button is disabled by, answered separately from
            // the lines so a closed window is not inferred from empty quantities.
            'return_open' => $window?->isReturnOpen() ?? false,
            'return_window_ends_at' => $window?->return_window_ends_at->toIso8601String(),
            'currency' => $payment?->currency->code,
            'lines' => $lines,
        ]);
    }

    private function customerId(): int
    {
        $actor = current_actor();

        if ($actor === null) {
            throw PaymentException::groupNotFound('');
        }

        return (int) $actor->getKey();
    }

    /**
     * The acting customer's own order, or the one answer everything else gets.
     *
     * BY SHAPE FIRST (ADR-059). `settlement_windows.order_uuid` is a native uuid
     * column on PostgreSQL, so a non-uuid segment would be SQLSTATE[22P02] — a
     * 500 on a page the customer opens. The trap, eleventh watch.
     */
    private function assertOwned(string $orderUuid, int $customerId): void
    {
        if (! PublicKey::looksLikeUuid($orderUuid)) {
            throw PaymentException::groupNotFound($orderUuid);
        }

        $fulfilment = $this->orders->orderFulfilment($orderUuid);

        if ($fulfilment === null || $fulfilment['customer_id'] !== $customerId) {
            throw PaymentException::groupNotFound($orderUuid);
        }
    }

    /**
     * The payment this order was charged on — for its CURRENCY, nothing else.
     *
     * Null when the basket was never paid, which the read reports as "nothing is
     * returnable" rather than as an error: an unpaid order is a cancellation, and
     * that is a different button.
     */
    private function paymentFor(string $orderUuid): ?Payment
    {
        $group = $this->orders->checkoutGroupFor($orderUuid);

        if ($group === null || $group === '') {
            return null;
        }

        return Payment::query()->with('currency')->where('checkout_group_uuid', $group)->first();
    }
}
