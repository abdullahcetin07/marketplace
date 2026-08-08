<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Order\Domain\Enums\ReturnRequestStatus;
use App\Modules\Order\Domain\Events\ReturnRejected;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\ReturnRequest;

/**
 * "Hayır, iade alamayız" — the seller declines (ADR-073).
 *
 * **NOTHING MOVES.** A row changes state and the sale stands exactly as it was.
 * The cheapest half of the flow, and the one that makes the expensive half
 * honest — a request that could only ever be approved would not be a request.
 *
 * **A REASON IS OPTIONAL IN THE SCHEMA AND REQUIRED BY THE PANEL**, the same
 * division C2 draws: the column is nullable because some future automation may
 * have nothing to say, while a human refusing a return without a word is the
 * support ticket the field exists to prevent. The rule lives where the actor is
 * known.
 *
 * **REJECTING DOES NOT CLOSE THE DOOR.** The unique index counts only open
 * requests, so the buyer may ask again while the window is still open —
 * deliberately, because the seller's answer was about this parcel's condition,
 * not about the buyer's right to ask.
 *
 * **IT DOES NOT END THE RETURN WINDOW EITHER.** A rejected buyer still has an
 * admin refund and, in principle, a consumer-rights claim; a seller's "no" is an
 * answer, not an expiry.
 *
 * @see docs/modules/Order.md §3.6
 */
final class RejectReturnAction extends BaseAction
{
    /** Held between `handle()` and `after()` so the event fires AFTER COMMIT. */
    private ?ReturnRequest $rejected = null;

    public function handle(mixed ...$arguments): ReturnRequest
    {
        /** @var ReturnRequest $request */
        $request = $arguments[0];
        $actorId = (int) $arguments[1];
        $reason = $arguments[2] ?? null;

        if ($request->status !== ReturnRequestStatus::Requested) {
            /*
            | AN APPROVED REQUEST CANNOT BE REJECTED. The buyer has a return code
            | and may already have posted the parcel — withdrawing the approval
            | would leave goods in transit against a return that officially never
            | happened.
            */
            throw OrderException::returnAlreadyDecided($request->uuid);
        }

        $request->forceFill([
            'status' => ReturnRequestStatus::Rejected,
            'decision_reason' => $reason,
            'decided_by' => $actorId,
            'decided_at' => now(),
        ])->save();

        $this->rejected = $request;

        return $request;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->rejected !== null) {
            event(new ReturnRejected(
                returnRequestUuid: $this->rejected->uuid,
                orderUuid: $this->rejected->order_uuid,
                reason: $this->rejected->decision_reason,
            ));
        }
    }
}
