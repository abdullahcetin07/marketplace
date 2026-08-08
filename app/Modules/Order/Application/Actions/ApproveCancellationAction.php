<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Domain\Contracts\OrderCancellationContract;
use App\Modules\Order\Domain\Enums\CancellationRequestStatus;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\CancellationRequest;

/**
 * "Tamam, iptal edelim" — the seller agrees (ADR-065, C2).
 *
 * **IT REFUNDS THE WHOLE ORDER, THROUGH C1'S PORT, AND OWNS NONE OF THAT.** The
 * money, the proportional commission reversal, the restock and the parcel's
 * closing are all `RefundLinesAction`'s, reached through
 * `OrderCancellationContract` — the same door the seller's own line-level cancel
 * goes through. C2 adds an approval workflow, not a second way to move money.
 *
 * **"EVERY REMAINING LINE" IS ASKED, NOT ASSUMED.** The quantities come from the
 * port's own `cancellableQuantities()`, so a line the seller already cancelled
 * last week is not refunded twice and a line already partly returned is refunded
 * only for what is left. Reading the order's own quantities and passing those
 * would be Order doing arithmetic that belongs to Payment.
 *
 * **THE REFUND GOES FIRST AND THE ROW IS STAMPED AFTER**, which is the only
 * ordering that fails safely. Stamping first and refunding second would leave an
 * `approved` request with the buyer's money still taken if the PSP refused —
 * every surface would say the cancellation happened and no money would have
 * moved. The other way round, a failure after the refund leaves a `pending`
 * request beside a cancelled order: visibly odd, trivially readable, and the
 * money is already right.
 *
 * **NOT A `BaseAction`, deliberately.** `RefundLinesAction` owns the one
 * transaction here, and it dispatches `PaymentRefunded` after ITS commit.
 * Wrapping this in an outer transaction would turn that commit into a savepoint
 * release, so Order and Shipping would move an order and a parcel on the strength
 * of a transaction still able to roll back. The same reasoning
 * `CompleteReturnRequestAction` states on the return's own completion (ADR-073).
 *
 * @see docs/modules/Order.md §3.3
 */
final class ApproveCancellationAction
{
    public function __construct(private readonly OrderCancellationContract $cancellation) {}

    public function run(CancellationRequest $request, string $sellerOrgUuid, int $actorId): CancellationRequest
    {
        if (! $request->isOpen()) {
            // Already answered. A refusal rather than a no-op: a seller who
            // clicked approve on a rejected request has misread the screen, and
            // silently refunding on that basis is the expensive reading.
            throw OrderException::cancellationAlreadyDecided($request->uuid);
        }

        $quantities = $this->cancellation->cancellableQuantities($request->order_uuid, $sellerOrgUuid);

        if ($quantities === []) {
            /*
            | NOTHING LEFT TO CANCEL — the parcel shipped while the request sat,
            | or the order was already fully cancelled another way. The request
            | cannot be approved, and saying so is better than approving
            | something that would refund nothing.
            */
            throw OrderException::notCancellableByRequest($request->order_uuid);
        }

        $this->cancellation->cancelLinesBySeller(
            orderUuid: $request->order_uuid,
            sellerOrgUuid: $sellerOrgUuid,
            quantities: $quantities,
            // The BUYER's words travel with the money, because it is their
            // cancellation — the order screen shows it back to them, and a
            // seller's silent approval would leave the reason blank.
            reason: $request->reason,
            actorId: $actorId,
        );

        $request->forceFill([
            'status' => CancellationRequestStatus::Approved,
            'decided_by' => $actorId,
            'decided_at' => now(),
        ])->save();

        return $request;
    }
}
