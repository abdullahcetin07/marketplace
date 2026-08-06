<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * What became of a buyer's "iptal edebilir miyiz?" (ADR-065, C2).
 *
 * **THREE CASES AND NO FOURTH.** There is no `cancelled`, no `expired` and no
 * `withdrawn`, and each absence is a decision:
 *
 *   - **No `cancelled`.** An approved request produces a cancelled ORDER; the
 *     request itself only ever records that the seller said yes. Two rows both
 *     claiming to hold the cancellation is how they end up disagreeing.
 *   - **No `expired`.** A request nobody answers stays `pending` forever, and
 *     that is the honest state: the seller has not answered. Auto-expiring it
 *     would quietly convert the platform's silence into a refusal.
 *   - **No `withdrawn`.** A buyer who changes their mind back can simply take
 *     delivery — the request costs them nothing while it sits — and giving them a
 *     second button to press would be a third way for the shipment gate to be
 *     raced.
 *
 * **`pending` IS THE ONLY LIVE ONE, AND THAT IS WHAT THE UNIQUE INDEX KEYS ON.**
 * One open request per order; a rejected request does not block asking again,
 * because circumstances change and a seller who said no last week may say yes
 * when the item still has not shipped.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see App\Modules\Order\Domain\Models\CancellationRequest
 */
enum CancellationRequestStatus: string
{
    use HasEnumHelpers;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Whether the seller has yet to answer — the state the UNIQUE index counts.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    public function label(): string
    {
        return __("enums.CancellationRequestStatus.{$this->value}");
    }
}
