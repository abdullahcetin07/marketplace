<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

/**
 * Why a customer has these points (ADR-081).
 *
 * **AN ENUM RATHER THAN A LOOKUP TABLE**, by the platform's own test: adding a
 * source means writing the code that earns it — a listener, a sweep, a rate to
 * read. An operator cannot invent "birthday points" from an admin screen and have
 * them appear; that is a release, so it is an enum.
 *
 * **PHASE 1 CARRIES THREE CASES AND NOT FIVE.** `Redemption` and `Reversal` belong
 * to ADR-084 and are deliberately absent: a case nothing can write is a promise the
 * ledger cannot keep, and the storefront would have to guess what an empty category
 * means.
 */
enum LoyaltyPointSource: string
{
    /** Joining, once per customer. */
    case Signup = 'signup';

    /** A review that passed moderation — not one that was merely written. */
    case Review = 'review';

    /** A seller-order whose return window closed without a return. */
    case Purchase = 'purchase';

    /**
     * The label a storefront shows beside the row.
     *
     * Turkish here rather than in a lang file because the API returns it as data,
     * and a client that had to map three codes to three strings would be a second
     * place for them to drift.
     */
    public function label(): string
    {
        return match ($this) {
            self::Signup => 'Üyelik',
            self::Review => 'Değerlendirme',
            self::Purchase => 'Alışveriş',
        };
    }
}
