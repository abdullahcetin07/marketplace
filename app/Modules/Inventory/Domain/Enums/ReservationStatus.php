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
     * A committed hold whose units came back — a refund or a return (P5).
     *
     * A SEPARATE TERMINAL STATE RATHER THAN A RETURN TO `released`, because the
     * two mean different things: `released` is a hold that never became a sale,
     * and this is a sale that was undone. Reusing `released` would make the
     * reservation history claim the units never left, which is exactly the fact a
     * dispute turns on.
     *
     * IT IS ALSO WHAT MAKES RESTOCK IDEMPOTENT: a reference already in this state
     * cannot be restocked again, so a retried refund cannot inflate a seller's
     * stock.
     */
    case Restocked = 'restocked';

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

    /**
     * Whether these units have left and could still be sent back — the one
     * question `restock` asks (P5).
     *
     * ONLY `Committed`. An active hold is released, not restocked; a released one
     * never left; and an already-restocked one must not be restocked twice, which
     * is what makes a retried refund safe.
     */
    public function isRestockable(): bool
    {
        return $this === self::Committed;
    }

    /**
     * Whether this reference may be HELD AGAIN under its own name (ADR-072).
     *
     * **ONLY `Released`, and the exclusions are the point.** `Active` already
     * holds the units — re-taking it would count them twice, and that is the
     * idempotency `reserve()` has always promised a retrying caller. `Committed`
     * and `Restocked` are sales: those units left, and a hold placed on stock
     * that has already gone would let the same reference be commited twice.
     *
     * Added for Payment's late-callback recovery, where an expired order's holds
     * were given back and the customer's 3-D Secure then succeeded.
     */
    public function isReclaimable(): bool
    {
        return $this === self::Released;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'warning',
            self::Released => 'gray',
            self::Committed => 'success',
            self::Restocked => 'info',
        };
    }
}
