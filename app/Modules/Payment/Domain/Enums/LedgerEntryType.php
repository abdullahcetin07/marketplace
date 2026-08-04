<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Why a seller's balance moved (ADR-062, Payment.md §7).
 *
 * SIX REASONS, AND THE SIGN IS PART OF THE MEANING. A `sale_credit` is money the
 * seller earned; a `commission_debit` is the platform's share of that same sale. A
 * ledger row that only said "+12 990" would leave "why?" unanswerable, and "why?"
 * is the entire question a seller opens their balance page to ask.
 *
 * THE SIGN LIVES HERE, NOT AT THE CALL SITE. `signFor()` is what stops a caller
 * appending a positive `commission_debit` and quietly paying the seller the
 * platform's cut. The amount passed in is always a magnitude; this decides which
 * way it points, once, for everybody.
 *
 * TWO OF THE SIX ARE NOT WRITTEN YET — `refund_debit` and
 * `refund_commission_credit` land in P5. Unlike `OrderStatus`, which
 * deliberately withheld cases nothing could set, these are declared now because
 * the SIGN CONVENTION is the thing being defined and it has to be complete to be
 * coherent: a refund reverses a sale, and stating that here is what makes the
 * reversal's direction obvious when P5 writes it rather than a decision taken
 * under deadline.
 *
 * A REFUND REVERSES BOTH SIDES. `refund_debit` takes back the sale credit and
 * `refund_commission_credit` gives back the commission — because the platform
 * charged commission on a sale that did not, in the end, happen. Two entries
 * rather than one net figure, so the balance page can still explain itself.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Payment.md §7
 */
enum LedgerEntryType: string
{
    use HasEnumHelpers;

    /** The seller earned this — the order's KDV-inclusive total. */
    case SaleCredit = 'sale_credit';

    /** The platform's share of that sale (ADR-061). */
    case CommissionDebit = 'commission_debit';

    /** Paid out to the seller's bank (P4). */
    case PayoutDebit = 'payout_debit';

    /** The sale was refunded, so the credit goes back (P5). */
    case RefundDebit = 'refund_debit';

    /** …and the commission the platform took on it goes back too (P5). */
    case RefundCommissionCredit = 'refund_commission_credit';

    /**
     * A payout the bank refused — the money never left, so the debit comes back
     * (P4).
     *
     * A SIXTH TYPE, WHICH ADR-062 DOES NOT LIST — added 2026-08-04 and reported
     * for ratification. The ADR enumerates five and P4 needs a sixth, because the
     * debit is appended when a payout is CREATED (the money is committed the
     * moment an admin decides to send it, which is what stops two admins each
     * paying out the same balance) and a transfer can still be rejected afterwards.
     *
     * WITHOUT IT A SELLER IS PERMANENTLY SHORT. The ledger is append-only, so the
     * debit cannot be deleted; and none of the five existing types means "that
     * payout did not happen" — using `refund_commission_credit` for it would put a
     * refund in the balance history of a sale nobody refunded. The alternative
     * shape, debiting only when a payout is marked PAID, was rejected because it
     * lets two pending payouts each pass the balance guard and overdraw when both
     * later succeed.
     */
    case PayoutReversalCredit = 'payout_reversal_credit';

    /**
     * Which way this kind of entry moves a balance: `+1` or `-1`.
     *
     * THE ONE PLACE THE SIGN IS DECIDED. A caller passes a magnitude and this
     * points it, so no call site can append a positive commission and pay the
     * seller the platform's cut by accident.
     */
    public function sign(): int
    {
        return match ($this) {
            self::SaleCredit, self::RefundCommissionCredit, self::PayoutReversalCredit => 1,
            self::CommissionDebit, self::PayoutDebit, self::RefundDebit => -1,
        };
    }

    /**
     * The signed amount for a magnitude — what actually goes in the column.
     *
     * A NEGATIVE MAGNITUDE IS TREATED AS ITS ABSOLUTE VALUE rather than flipping
     * the sign back: "debit −500" can only be a caller mistake, and honouring it
     * would credit the seller 500 for a payout.
     */
    public function signedAmount(int $magnitudeMinor): int
    {
        return $this->sign() * abs($magnitudeMinor);
    }

    public function label(): string
    {
        return __("payment.ledger.type.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::SaleCredit, self::RefundCommissionCredit => 'success',
            self::CommissionDebit, self::RefundDebit => 'warning',
            self::PayoutDebit, self::PayoutReversalCredit => 'info',
        };
    }
}
