<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The life of one hold on stock (§2.4).
 *
 *   Active    — units are held; they count against `available` and against
 *               nothing else.
 *   Released  — the hold was given back (a cancelled or expired checkout).
 *   Committed — the hold became a sale; the units left.
 *
 * BOTH ENDINGS ARE TERMINAL, and that is what makes release and commit
 * idempotent: a repeat call finds a reservation that is no longer `Active` and
 * does nothing, rather than decrementing a second time. A double commit on a
 * retried webhook is the failure this shape exists to make impossible.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Inventory.md §3.2
 */
enum ReservationStatus: string
{
    use HasEnumHelpers;

    case Active = 'active';
    case Released = 'released';
    case Committed = 'committed';

    /**
     * Whether this reservation still holds units — the one question `release`
     * and `commit` ask before acting.
     */
    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this !== self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'warning',
            self::Released => 'gray',
            self::Committed => 'success',
        };
    }
}
