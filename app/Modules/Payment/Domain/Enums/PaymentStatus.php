<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Where one checkout group's money has got to (Payment.md §4).
 *
 * ```
 * pending ──▶ paid ──▶ (refunded | partially_refunded)
 *    │
 *    └──▶ failed | expired
 * ```
 *
 * `pending → paid` HAPPENS ONLY THROUGH A HASH-VERIFIED SUCCESS CALLBACK. Not
 * through an admin action, not through the browser redirect, not through a retry
 * of `initiate`. That is the single most important sentence about this enum: it
 * is the state that says money arrived, and exactly one thing on the platform is
 * allowed to set it.
 *
 * `expired` IS DISTINCT FROM `failed`, and the difference is what the buyer did.
 * Failed means the PSP refused a real attempt — a declined card, a failed 3-D
 * Secure. Expired means nobody ever tried: the iframe was opened and abandoned.
 * Both release the stock, so the distinction buys nothing operationally today —
 * it buys an honest answer to "did this customer try to pay us?", which is a
 * support question and a fraud question, and neither is answerable later from a
 * status that merged them.
 *
 * THE REFUND CASES EXIST BEFORE P5 BUILDS THEM, unlike `OrderStatus`, which
 * deliberately withheld its forward-looking cases. The reason is different here:
 * these are reachable within THIS module's own phases from a state P1 already
 * writes, and the `paid` row's transitions are what the P1 tests assert against.
 * An enum whose terminal state claims to be terminal, when the next phase of the
 * same module makes it not, would have to be corrected rather than extended.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Payment.md §4
 */
enum PaymentStatus: string
{
    use HasEnumHelpers;

    case Pending = 'pending';

    case Paid = 'paid';

    case Failed = 'failed';

    case Expired = 'expired';

    case Refunded = 'refunded';

    case PartiallyRefunded = 'partially_refunded';

    /**
     * The states this one may still move to.
     *
     * @return array<int, self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Failed, self::Expired],
            // Money that arrived can only go back out.
            self::Paid => [self::Refunded, self::PartiallyRefunded],
            // A partial refund may be followed by more, up to the whole amount.
            self::PartiallyRefunded => [self::Refunded, self::PartiallyRefunded],
            // Nothing was collected, so there is nothing to reverse. A buyer who
            // wants to try again starts a new payment rather than reviving this
            // one — a failed attempt is evidence, and reusing its row would erase
            // the evidence.
            self::Failed, self::Expired, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /**
     * Whether money was actually collected against this payment.
     *
     * The question the stock commit, the ledger and every report ask — asked in
     * one place so "is it paid" cannot come to mean two things once a partial
     * refund exists. A partially refunded payment DID collect.
     */
    public function isSettled(): bool
    {
        return $this === self::Paid
            || $this === self::Refunded
            || $this === self::PartiallyRefunded;
    }

    /**
     * Whether this payment is still waiting on the buyer — the only state a
     * re-`initiate` may reuse rather than replace.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('payment.status.pending'),
            self::Paid => __('payment.status.paid'),
            self::Failed => __('payment.status.failed'),
            self::Expired => __('payment.status.expired'),
            self::Refunded => __('payment.status.refunded'),
            self::PartiallyRefunded => __('payment.status.partially_refunded'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Paid => 'success',
            self::Failed, self::Expired => 'danger',
            self::Refunded, self::PartiallyRefunded => 'gray',
        };
    }
}
