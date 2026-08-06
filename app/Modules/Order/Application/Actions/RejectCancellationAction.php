<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Order\Domain\Enums\CancellationRequestStatus;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\CancellationRequest;

/**
 * "Hayır, hazırlıyoruz" — the seller declines (ADR-065, C2).
 *
 * **THE ORDER DOES NOT MOVE, AND NEITHER DOES ANY MONEY.** That is the entire
 * operation: a row changes state and the sale proceeds exactly as it was. It is
 * the cheapest half of C2 and the one that makes the expensive half safe — a
 * buyer's request that could only ever be approved would not be a request.
 *
 * **A REASON IS OPTIONAL IN THE SCHEMA AND REQUIRED BY THE SURFACE.** The column
 * is nullable because a request rejected by some future automation may have
 * nothing to say; the seller's panel demands one, because refusing somebody's
 * cancellation without a word is the support ticket this field exists to prevent.
 * The rule lives where the actor is known, not in the table.
 *
 * **REJECTING DOES NOT CLOSE THE DOOR.** The UNIQUE index counts only `pending`
 * rows, so the buyer may ask again while the item still has not shipped —
 * deliberately, because the seller's answer was about this week's stock, not
 * about the buyer's right to ask.
 *
 * @see docs/modules/Order.md §3.3
 */
final class RejectCancellationAction extends BaseAction
{
    public function handle(mixed ...$arguments): CancellationRequest
    {
        /** @var CancellationRequest $request */
        $request = $arguments[0];
        $actorId = (int) $arguments[1];
        $reason = $arguments[2] ?? null;

        if (! $request->isOpen()) {
            // Already answered — including already APPROVED, where a rejection
            // would claim the order proceeds while its money has gone back.
            throw OrderException::cancellationAlreadyDecided($request->uuid);
        }

        $request->forceFill([
            'status' => CancellationRequestStatus::Rejected,
            'decision_reason' => $reason,
            'decided_by' => $actorId,
            'decided_at' => now(),
        ])->save();

        return $request;
    }
}
