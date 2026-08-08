<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Domain\Contracts\OrderReturnContract;
use App\Modules\Order\Domain\Enums\ReturnRequestStatus;
use App\Modules\Order\Domain\Events\ReturnCompleted;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\ReturnRequest;

/**
 * "İadeyi tamamla" — the parcel is back on the seller's shelf, so the money goes
 * back to the buyer (ADR-073).
 *
 * **THIS IS WHERE THE REFUND MOVED TO.** ADR-064 fired it on the buyer's request;
 * ADR-073 fires it here, when the seller confirms they have the goods. Not one
 * kuruş of the arithmetic changed — `RefundLinesAction` is untouched, reached
 * through the Core return port with `cause: return`. What changed is who pulls
 * the trigger and when.
 *
 * **REFUND FIRST, STAMP SECOND — the only ordering that fails safely**, and the
 * same one `ApproveCancellationAction` states. Stamping first would leave a
 * `completed` return beside a buyer whose money never came back if the PSP
 * refused: every surface would say the return finished. The other way round, a
 * failure after the refund leaves an `approved` request beside a refunded order —
 * visibly odd, trivially readable, and the money is already right.
 *
 * **NOT A `BaseAction`, deliberately.** `RefundLinesAction` owns the one
 * transaction here and dispatches `PaymentRefunded` after ITS commit. An outer
 * transaction would turn that commit into a savepoint release, so Order and
 * Shipping would move an order and a parcel on the strength of a transaction still
 * able to roll back.
 *
 * **THE QUANTITIES ARE THE REQUEST'S, AND THEY ARE RE-CHECKED BEHIND THE PORT.**
 * A request can sit for days between being approved and the parcel arriving, and
 * an admin may have refunded one of its lines meanwhile — so what was asked is the
 * input and `RefundableLines` has the last word.
 *
 * **IT DOES NOT SET THE ORDER'S STATUS.** `PaymentRefunded` with `cause = return`
 * does, through Order's own listener, exactly as every other refund on this
 * platform does. This action stamps one row: the return request's.
 *
 * @see docs/modules/Order.md §3.6
 */
final class CompleteReturnRequestAction
{
    public function __construct(private readonly OrderReturnContract $returns) {}

    public function run(ReturnRequest $request, string $sellerOrgUuid, int $actorId): ReturnRequest
    {
        if ($request->status !== ReturnRequestStatus::Approved) {
            /*
            | ONLY AN APPROVED RETURN COMPLETES. A `requested` one has not been
            | agreed to and the buyer has been given no way to send anything back;
            | completing it would refund a parcel still sitting in their hallway.
            */
            throw OrderException::returnNotApproved($request->uuid);
        }

        $quantities = $request->line_quantities;

        if ($quantities === []) {
            throw OrderException::returnNotApproved($request->uuid);
        }

        // THE MONEY, FIRST. A throw from here leaves the request `Approved`, which
        // is the truthful state: the seller may try again once the PSP is happy.
        $this->returns->completeReturnBySeller(
            orderUuid: $request->order_uuid,
            sellerOrgUuid: $sellerOrgUuid,
            quantities: $quantities,
            actorId: $actorId,
        );

        $request->forceFill([
            'status' => ReturnRequestStatus::Completed,
            'completed_by' => $actorId,
            'completed_at' => now(),
        ])->save();

        event(new ReturnCompleted(
            returnRequestUuid: $request->uuid,
            orderUuid: $request->order_uuid,
        ));

        return $request;
    }
}
