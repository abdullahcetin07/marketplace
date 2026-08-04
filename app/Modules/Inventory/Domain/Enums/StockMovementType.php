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
     * A committed sale came back — a refund or a return (Payment.md §8, P5).
     *
     * THE PRIMITIVE ORDER.md §12.5 SAID WOULD NEED ITS OWN RULING BEFORE PAYMENT
     * SHIPPED, added 2026-08-04 now that Payment refunds. It is deliberately NOT
     * `release`: releasing a hold means units that never left are free again,
     * while this means units that DID leave have physically come back. Conflating
     * them in one type would make "why did my stock go up?" unanswerable, which is
     * the exact question the type column exists for.
     *
     * IT IS ALSO NOT `seller_adjustment`. A seller correcting their own count and
     * a customer returning goods move the same number, and a merchant reading
     * their movement list needs to tell them apart — one is their doing and the
     * other is not.
     */
    case Restocked = 'restocked';

    /**
     * Whether this type moves the on-hand count.
     *
     * Only two do, and they move it in opposite directions: a seller adding
     * stock, and a sale taking it away. Reserving and releasing move nothing
     * physical — that is the whole distinction reservations exist to draw.
     */
    public function movesOnHand(): bool
    {
        // Three now, and `Restocked` is the only one that moves it UP for a
        // reason other than the seller saying so.
        return in_array($this, [self::SellerAdjustment, self::Committed, self::Restocked], true);
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
            self::Restocked => 'gray',
        };
    }
}
