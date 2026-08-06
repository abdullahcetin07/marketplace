<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Payment\Domain\DTOs\ReturnRequestDTO;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\SettlementWindow;
use App\Shared\Support\PublicKey;

/**
 * "Bunu geri göndereceğim" — the buyer's own return (S4).
 *
 * **THIS IS THE CUSTOMER-FACING REFUND P5 DELIBERATELY DID NOT BUILD.** P5 left
 * refunds admin-only and said why: "cancel before dispatch" and "return after
 * delivery" could not be told apart without a fulfilment state, so a self-serve
 * button would have been granting a business rule nobody had written down.
 * Shipping wrote it — a delivery date and a return window (S3) — and this is the
 * button that rule now permits.
 *
 * THREE THINGS ARE CHECKED HERE AND NOWHERE ELSE:
 *
 *   OWNERSHIP  the order is the acting customer's. Asked of Order through the
 *              Core port, because a payment knows whose money it took and a
 *              refund needs to know whose ORDER it is.
 *   DELIVERED  there is a settlement window, which only a delivery creates. A
 *              buyer cannot return a parcel that has not arrived — that is a
 *              cancellation, a different operation with different consequences
 *              for the seller's stock.
 *   IN TIME    the window is still open. After it closes the goods are the
 *              buyer's, and a refund is an admin's judgement call again.
 *
 * IT IS A GUARD, NOT A SECOND REFUND IMPLEMENTATION. The money, the ledger, the
 * restock and the statuses are all `RefundLinesAction`'s — an admin refunding the
 * same lines must produce byte-identical results, and two code paths to the same
 * money is how they stop doing that.
 *
 * ONE ANSWER FOR EVERY REFUSAL. "Not yours", "never delivered", "too late" and
 * "no such order" all come back the same, because the alternative lets anyone
 * discover which order uuids are real and what happened to them by watching which
 * error changes.
 *
 * @see docs/modules/Payment.md §8
 */
final class RequestReturnAction
{
    public function __construct(
        private readonly OrderQueryContract $orders,
        private readonly RefundLinesAction $refund,
    ) {}

    /**
     * NOT A `BaseAction`, and deliberately: it owns no transaction because it
     * makes no writes. `RefundLinesAction` owns the one transaction there is, and
     * nesting a second would only obscure where the rollback boundary actually
     * lies.
     */
    public function run(ReturnRequestDTO $request, int $customerId): PaymentRefund
    {
        $this->assertOwned($request->orderUuid, $customerId);
        $this->assertWithinWindow($request->orderUuid);

        return $this->refund->run($request);
    }

    private function assertOwned(string $orderUuid, int $customerId): void
    {
        /*
        | BY SHAPE FIRST (ADR-059). `settlement_windows.order_uuid` and
        | `payment_refunds.order_uuid` are native uuid columns on PostgreSQL, so a
        | non-uuid segment would be SQLSTATE[22P02] — a 500 on a button the
        | customer taps. The trap, tenth watch.
        */
        if (! PublicKey::looksLikeUuid($orderUuid)) {
            throw PaymentException::groupNotFound($orderUuid);
        }

        $fulfilment = $this->orders->orderFulfilment($orderUuid);

        if ($fulfilment === null || $fulfilment['customer_id'] !== $customerId) {
            throw PaymentException::groupNotFound($orderUuid);
        }
    }

    /**
     * The parcel arrived, and not too long ago.
     *
     * NO WINDOW MEANS NOT DELIVERED, which is a refusal rather than an oversight:
     * only a delivery creates one (S3). A buyer whose parcel is still in transit
     * is asking to CANCEL, and that is a different operation — it has different
     * consequences for the seller's stock and it is not this button.
     */
    private function assertWithinWindow(string $orderUuid): void
    {
        $window = SettlementWindow::query()->where('order_uuid', $orderUuid)->first();

        if ($window === null || ! $window->isReturnOpen()) {
            throw PaymentException::returnWindowClosed($orderUuid);
        }
    }
}
