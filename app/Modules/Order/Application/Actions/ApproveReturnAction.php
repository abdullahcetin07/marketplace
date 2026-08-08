<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Order\Domain\Enums\ReturnRequestStatus;
use App\Modules\Order\Domain\Events\ReturnApproved;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\ReturnRequest;

/**
 * "Tamam, şu kodla gönderin" — the seller agrees to take it back (ADR-073).
 *
 * **NO MONEY MOVES HERE, AND THAT IS THE ENTIRE POINT OF ADR-073.** The
 * cancellation's approval refunds, because the goods never left and there is
 * nothing to wait for. A return's approval cannot: the parcel is still in the
 * buyer's hands, and refunding now is refunding on trust — which is exactly what
 * ADR-064 did and this amends. What this writes is an INSTRUCTION.
 *
 * **THE RETURN CODE IS THE INSTRUCTION.** It is free text because it is whatever
 * the merchant's own carrier contract calls it — a code, a reference, an address —
 * and v1 tracks no return parcel (ADR-063's manual philosophy). The carrier comes
 * from Shipping's active list through the Core port, so a carrier an operator
 * switched off cannot be handed to a buyer.
 *
 * **`Approved` IS STILL AN OPEN REQUEST.** The buyer is walking to the cargo desk;
 * a second return request for this order must still be refused, and the money is
 * still waiting on `CompleteReturnAction`.
 *
 * ALREADY-ANSWERED IS A REFUSAL, not a no-op — the same reasoning C2 states. A
 * seller approving a rejected request has misread the screen, and silently
 * re-approving on that basis is the expensive reading.
 *
 * @see docs/modules/Order.md §3.6
 */
final class ApproveReturnAction extends BaseAction
{
    /** Held between `handle()` and `after()` so the event fires AFTER COMMIT. */
    private ?ReturnRequest $approved = null;

    public function handle(mixed ...$arguments): ReturnRequest
    {
        /** @var ReturnRequest $request */
        $request = $arguments[0];
        $returnCode = (string) $arguments[1];
        $cargoCompanyUuid = $arguments[2] ?? null;
        $actorId = (int) $arguments[3];

        if ($request->status !== ReturnRequestStatus::Requested) {
            /*
            | ONLY A FRESH REQUEST MAY BE APPROVED — including not an already
            | APPROVED one, where re-approving would quietly replace the return
            | code the buyer has already been given and is holding a printout of.
            */
            throw OrderException::returnAlreadyDecided($request->uuid);
        }

        $request->forceFill([
            'status' => ReturnRequestStatus::Approved,
            'return_code' => $returnCode,
            'cargo_company_uuid' => $cargoCompanyUuid,
            'decided_by' => $actorId,
            'decided_at' => now(),
        ])->save();

        $this->approved = $request;

        return $request;
    }

    /**
     * AFTER COMMIT. The obvious listener is a "iade kodunuz hazır" notice to the
     * buyer; none ships in v1, so the event is a hook rather than a feature.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->approved !== null) {
            event(new ReturnApproved(
                returnRequestUuid: $this->approved->uuid,
                orderUuid: $this->approved->order_uuid,
                returnCode: (string) $this->approved->return_code,
            ));
        }
    }
}
