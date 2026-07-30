<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Why a stock movement happened (§2.4, ADR-050).
 *
 *   SellerAdjustment — the seller changed their declared on-hand, mirrored from
 *                      the Offer form. The only thing that moves on-hand up.
 *   Reserved         — units held for an in-flight checkout. `on_hand` is
 *                      untouched; `reserved` rises.
 *   Released         — a cancelled or expired hold gives its units back.
 *   Committed        — a completed sale. BOTH numbers fall: the units truly
 *                      left, and the hold that was covering them ends with them.
 *
 * THE TYPE IS WHY THE LEDGER EXISTS. A bare counter that drops by three cannot
 * say whether three sold or three are merely held, and that ambiguity is exactly
 * what a stock dispute turns on. The deltas record what moved; this records what
 * it meant.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Inventory.md §2.2
 */
enum StockMovementType: string
{
    use HasEnumHelpers;

    case SellerAdjustment = 'seller_adjustment';
    case Reserved = 'reserved';
    case Released = 'released';
    case Committed = 'committed';

    /**
     * Whether this type moves the on-hand count.
     *
     * Only two do, and they move it in opposite directions: a seller adding
     * stock, and a sale taking it away. Reserving and releasing move nothing
     * physical — that is the whole distinction reservations exist to draw.
     */
    public function movesOnHand(): bool
    {
        return in_array($this, [self::SellerAdjustment, self::Committed], true);
    }

    /**
     * Badge colour for the movement history a seller reads.
     */
    public function color(): string
    {
        return match ($this) {
            self::SellerAdjustment => 'gray',
            self::Reserved => 'warning',
            self::Released => 'info',
            self::Committed => 'success',
        };
    }
}
