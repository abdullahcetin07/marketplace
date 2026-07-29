<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The lifecycle of a seller's offer (Offer.md §2.2, §3.1).
 *
 *   Active    — live and eligible for the buy box, subject to stock.
 *   Paused    — the seller temporarily hides it. Excluded from the buy box,
 *               retained with its price so resuming is one click.
 *   Withdrawn — the seller removes it (soft-deleted). Terminal from their side;
 *               it never appears anywhere again, but the row survives because
 *               past orders will reference it.
 *   Suspended — an admin's reactive oversight action (ADR-044). Excluded
 *               everywhere until reinstated, which restores the exact prior
 *               state from `status_before_suspension` — the Store pattern.
 *
 * THERE IS NO `OutOfStock` CASE (ADR-043/045). Out-of-stock is `Active &&
 * stock_quantity = 0`, computed. Storing it would be a second source of truth
 * for the same fact, and the two would drift the first time a seller restocked
 * without touching status.
 *
 * THERE IS NO MODERATION STATE EITHER (ADR-044). Unlike a catalog product, an
 * offer has no Draft/PendingReview: the product was already moderated, and price
 * and stock are the seller's commercial freedom. `create → Active` immediately.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Offer.md §3.1
 */
enum OfferStatus: string
{
    use HasEnumHelpers;

    case Active = 'active';
    case Paused = 'paused';
    case Withdrawn = 'withdrawn';
    case Suspended = 'suspended';

    /**
     * The statuses a SELLER may move their own offer to.
     *
     * Suspended is absent deliberately — a seller cannot suspend themselves out
     * of an admin's reach, and cannot lift a suspension by pausing and
     * resuming.
     *
     * @return array<int, self>
     */
    public function sellerTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Paused, self::Withdrawn],
            self::Paused => [self::Active, self::Withdrawn],
            // Terminal for the seller; a new offer is a new row (§3.2 — a
            // withdrawn offer does not block creating a fresh one).
            self::Withdrawn => [],
            // While suspended the seller can do nothing at all: pausing or
            // withdrawing would let them erase the state an admin is acting on.
            self::Suspended => [],
        };
    }

    public function canSellerTransitionTo(self $target): bool
    {
        return in_array($target, $this->sellerTransitions(), true);
    }

    /**
     * Statuses that still count toward the one-active-offer-per-(org, variant)
     * rule (§3.2). Withdrawn is excluded — that is what lets a seller who
     * withdrew an offer list the variant again later.
     *
     * @return array<int, self>
     */
    public static function blockingDuplicates(): array
    {
        return [self::Active, self::Paused, self::Suspended];
    }

    /**
     * Whether an offer in this status may compete for the buy box at all.
     *
     * Necessary, not sufficient: eligibility also needs stock and an active
     * store (§5). This answers only the status half.
     */
    public function isBuyBoxEligible(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the offer may still be shown on a product page, greyed, so a
     * buyer sees the seller exists (§3.3, §5). Suspended and withdrawn offers
     * never appear — one is an oversight action, the other is gone.
     */
    public function isPubliclyListable(): bool
    {
        return in_array($this, [self::Active, self::Paused], true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Withdrawn;
    }

    /**
     * Badge colour for the seller and admin panels.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'warning',
            self::Withdrawn => 'gray',
            self::Suspended => 'danger',
        };
    }
}
