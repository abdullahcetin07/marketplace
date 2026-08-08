<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Payment\Domain\DTOs\ReturnRequestDTO;
use App\Modules\Payment\Domain\Enums\RefundCause;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\PaymentRefundLine;
use App\Modules\Payment\Domain\Models\SettlementWindow;
use App\Shared\Support\PublicKey;

/**
 * "Ürünü teslim aldım" — the seller has the parcel back, so the money goes back
 * (ADR-073).
 *
 * **THE THIRD DOOR ONTO ONE MACHINE.** `RefundPaymentRequest` is the admin's,
 * `CancelOrderLinesAction` is the seller's before handover, and this is the
 * seller's after delivery. All three check who is asking and whether the moment is
 * right, and all three then hand the identical DTO to `RefundLinesAction`. A
 * fourth refund implementation is how four code paths for one number stop
 * agreeing.
 *
 * (A buyer's door existed too, `RequestReturnAction`, and ADR-073 DELETED it: the
 * buyer now writes a request in Order and this is what honours it.)
 *
 * **IT REPLACED A TRIGGER, NOT A CALCULATION.** ADR-064 fired the refund on the
 * buyer's REQUEST — the window was the approval — which for physical goods means
 * paying out before the seller has anything back. ADR-073 moved the firing here.
 * Not one kuruş of the arithmetic changed; the caller did.
 *
 * THREE THINGS ARE CHECKED HERE AND NOWHERE ELSE:
 *
 *   OWNERSHIP  the order is sold BY this seller org — asked of Order through the
 *              Core port. Checked even though the panel already scoped its query,
 *              because a panel's tenancy is a query somebody can get wrong, and
 *              this is the side of the port that cannot be.
 *   IN WINDOW  there is a settlement window and it is still open. **NOT
 *              `isAwaitingHandover`** — that is C1's gate and it refuses exactly
 *              the state a return begins in. A window exists only where a
 *              delivery happened (S3), so this one check carries both "it
 *              arrived" and "not too long ago".
 *   QUANTITY   `RefundableLines`, unchanged: a line goes back up to what has not
 *              already gone back, and asking for three of two is a refusal rather
 *              than a clamp.
 *
 * **THE QUANTITIES ARE RE-CHECKED AT COMPLETION, WHICH MATTERS MORE HERE THAN
 * ANYWHERE ELSE.** A return request can sit for days between the buyer writing it
 * and the parcel arriving, and an admin may have refunded one of its lines in
 * between. The caps Order showed the seller are a hint; this is where the number
 * is decided.
 *
 * **IT DOES NOT MOVE THE ORDER, THE PARCEL OR THE REQUEST.**
 * `RefundLinesAction` announces `PaymentRefunded` with `cause = return`, and Order
 * and Shipping each move their own state from it. The `ReturnRequest` row is
 * stamped by Order, AFTER this returns — refund first, stamp second, the only
 * ordering that fails safely (ADR-065).
 *
 * ONE ANSWER FOR EVERY REFUSAL, the same as its two siblings.
 *
 * @see docs/modules/Payment.md §8
 */
final class CompleteReturnAction
{
    public function __construct(
        private readonly OrderQueryContract $orders,
        private readonly RefundLinesAction $refund,
    ) {}

    /**
     * NOT A `BaseAction`: it owns no transaction because it makes no writes.
     * `RefundLinesAction` owns the one transaction there is, and nesting a second
     * would only obscure where the rollback boundary lies — the same reasoning
     * both sibling doors state.
     *
     * @param array<string, int> $quantities order line uuid => units coming back
     */
    public function run(
        string $orderUuid,
        string $sellerOrgUuid,
        array $quantities,
        ?int $actorId = null,
    ): PaymentRefund {
        $this->assertSoldBy($orderUuid, $sellerOrgUuid);
        $this->assertWithinWindow($orderUuid);

        return $this->refund->run(new ReturnRequestDTO(
            orderUuid: $orderUuid,
            quantities: $quantities,
            reason: null,
            actorId: $actorId,
            /*
            | THE ONE FIELD THAT MAKES THIS A RETURN. It decides whether the order
            | ends `refunded` or `cancelled` and the parcel `returned` or
            | `cancelled` (ADR-065) — the money is identical at both ends of the
            | lifecycle and the meaning is not.
            */
            cause: RefundCause::Return,
        ));
    }

    /**
     * Whether a return may still start from this order at all.
     *
     * ONE BOOLEAN FOR "NEVER DELIVERED" AND "TOO LATE", because a caller has no
     * business telling a buyer which — and because a window exists only where a
     * delivery happened, so the two questions have one answer.
     */
    public function isOpen(string $orderUuid): bool
    {
        if (! PublicKey::looksLikeUuid($orderUuid)) {
            return false;
        }

        $window = SettlementWindow::query()->where('order_uuid', $orderUuid)->first();

        return $window !== null && $window->isReturnOpen();
    }

    /**
     * What is still returnable on this order — line uuid => units.
     *
     * THE FORM'S CAPS, NOT THE GUARD, exactly as `CancelOrderLinesAction::cancellable()`
     * is. It is also what Order validates a buyer's ask against when the request is
     * WRITTEN, which is a different moment from when it is honoured — hence the
     * re-check in `run()`.
     *
     * EMPTY WHEN NOTHING IS RETURNABLE — closed window, no such order, everything
     * already back — so a surface that renders nothing renders the right thing.
     *
     * @return array<string, int>
     */
    public function returnable(string $orderUuid): array
    {
        if (! $this->isOpen($orderUuid)) {
            return [];
        }

        $remaining = [];

        foreach ($this->orders->orderLines($orderUuid) as $line) {
            $left = max(0, $line['quantity'] - PaymentRefundLine::refundedQuantityFor($line['id']));

            if ($left > 0) {
                $remaining[$line['id']] = $left;
            }
        }

        return $remaining;
    }

    private function assertSoldBy(string $orderUuid, string $sellerOrgUuid): void
    {
        /*
        | BY SHAPE FIRST (ADR-059). `settlement_windows.order_uuid` and
        | `payment_refunds.order_uuid` are native uuid columns on PostgreSQL, so a
        | non-uuid value would be SQLSTATE[22P02] — a 500 on a panel button —
        | while SQLite quietly returns nothing. The trap, thirteenth watch.
        */
        if (! PublicKey::looksLikeUuid($orderUuid) || $sellerOrgUuid === '') {
            throw PaymentException::groupNotFound($orderUuid);
        }

        $fulfilment = $this->orders->orderFulfilment($orderUuid);

        if ($fulfilment === null || $fulfilment['selling_org_uuid'] !== $sellerOrgUuid) {
            throw PaymentException::groupNotFound($orderUuid);
        }
    }

    /**
     * Delivered, and not too long ago.
     *
     * NO WINDOW MEANS NOT DELIVERED — a refusal rather than an oversight, since
     * only a delivery creates one (S3). A seller completing a return for a parcel
     * that never arrived is either confused or testing what this button will do.
     */
    private function assertWithinWindow(string $orderUuid): void
    {
        $window = SettlementWindow::query()->where('order_uuid', $orderUuid)->first();

        if ($window === null || ! $window->isReturnOpen()) {
            throw PaymentException::returnWindowClosed($orderUuid);
        }
    }
}
