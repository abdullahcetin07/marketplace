<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * What became of a buyer's "iade etmek istiyorum" (ADR-073).
 *
 * **FOUR CASES WHERE THE CANCELLATION HAS THREE, AND THE EXTRA ONE IS THE WHOLE
 * ADR.** A pre-shipment cancellation is settled the moment the seller answers —
 * the goods never left, so approval and refund are one act. A return is not: the
 * seller approves a request for goods that are still in the buyer's hands, and
 * the money must not move until the parcel is back. `Approved` is therefore the
 * middle of the story rather than the end of it, and **`Completed` is where the
 * refund fires** (ADR-073 amends ADR-064, which refunded on the request).
 *
 * **`Approved` IS STILL OPEN.** It is the state where the buyer is walking to the
 * cargo desk, so a second return request for the same order must not be
 * accepted — which is why `isOpen()` covers two cases and the partial UNIQUE
 * index keys on both. The cancellation's index keys on `pending` alone because it
 * has no equivalent middle.
 *
 * **NO `cancelled`, `expired` OR `withdrawn`**, for the reasons
 * `CancellationRequestStatus` states and one more: a completed return produces a
 * REFUNDED ORDER, and the request only records that the seller received the goods.
 * Two rows both claiming to hold the refund is how they start disagreeing.
 *
 * The transitions are enforced in the actions rather than a table — `Requested →
 * Approved|Rejected`, `Approved → Completed`, and both endings terminal.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see App\Modules\Order\Domain\Models\ReturnRequest
 */
enum ReturnRequestStatus: string
{
    use HasEnumHelpers;

    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';

    /**
     * Whether this return is still running — the state the UNIQUE index counts.
     *
     * **TWO CASES, NOT ONE.** An approved return is not finished: the buyer still
     * has the goods and the money has not moved. Counting only `Requested` would
     * let a buyer open a second return for an order they are already returning.
     */
    public function isOpen(): bool
    {
        return $this === self::Requested || $this === self::Approved;
    }

    /**
     * Whether the seller has answered and can no longer change that answer.
     */
    public function isTerminal(): bool
    {
        return $this === self::Rejected || $this === self::Completed;
    }

    public function color(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::Completed => 'success',
        };
    }

    public function label(): string
    {
        return __("enums.ReturnRequestStatus.{$this->value}");
    }
}
