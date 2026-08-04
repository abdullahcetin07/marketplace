<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Where one transfer to a seller has got to (ADR-062, Payment.md §8).
 *
 * ```
 * pending ──▶ paid
 *    │
 *    └──▶ failed
 * ```
 *
 * IT DESCRIBES A BANK TRANSFER, NOT A SOFTWARE OPERATION. The platform is a single
 * merchant and pays its sellers itself (ADR-060 §2); **this software moves no
 * money.** `pending` means an admin has decided to send it, `paid` means a human
 * or a bank actually did and wrote down the reference, `failed` means the transfer
 * was rejected. Nothing here triggers anything at a bank.
 *
 * `pending` ALREADY DEBITED THE BALANCE, which is the non-obvious part. The
 * `payout_debit` is appended when the payout is CREATED, not when it is marked
 * paid — the money is committed the moment an admin commits to sending it, and
 * that is what stops two admins each paying out the same balance. So `failed` has
 * to give it back, which is what `LedgerEntryType::PayoutReversalCredit` is for.
 *
 * BOTH OUTCOMES ARE TERMINAL. A rejected transfer is not retried by editing this
 * row: the reversal credit puts the money back in the balance and the admin
 * creates a NEW payout. That keeps the failed attempt on the record, which is
 * exactly what somebody reconciling a bank statement needs.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Payment.md §8
 */
enum PayoutStatus: string
{
    use HasEnumHelpers;

    case Pending = 'pending';

    case Paid = 'paid';

    case Failed = 'failed';

    /**
     * @return array<int, self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Failed],
            // Terminal in both directions — see the class docblock.
            self::Paid, self::Failed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /**
     * Whether this payout is still holding the seller's money out of their
     * balance — true until the bank's answer is known either way.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return __("payment.payout.status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Paid => 'success',
            self::Failed => 'danger',
        };
    }
}
