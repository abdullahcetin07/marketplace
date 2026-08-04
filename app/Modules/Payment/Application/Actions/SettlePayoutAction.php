<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Enums\PayoutStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payout;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;

/**
 * Record what the bank did with a transfer (ADR-062, Payment.md §8).
 *
 * **IT CALLS NO BANK.** A human made the transfer, or the bank refused it; this
 * writes down which, and the reference they were given. That is the entire
 * single-merchant settlement model (ADR-060 §2) in one action.
 *
 * `paid` CHANGES NO BALANCE, and that surprises people. The seller's balance
 * already dropped when the payout was CREATED — the money was committed the
 * moment an admin committed to sending it, which is what stops two admins paying
 * out one balance. Marking it paid confirms what already happened to the balance;
 * it does not repeat it.
 *
 * `failed` IS THE ONE THAT MOVES MONEY BACK. The transfer never happened, so the
 * debit is reversed with a `payout_reversal_credit` — the sixth ledger type,
 * added for this and reported against ADR-062. The ledger is append-only, so the
 * debit cannot be deleted; a compensating credit is the only honest way to say
 * "that did not happen", and it leaves both facts on the trail for whoever
 * reconciles the bank statement.
 *
 * BOTH OUTCOMES ARE TERMINAL. A rejected transfer is retried by creating a NEW
 * payout, never by re-marking this one — `Payout::isSettling()` refuses a write
 * that does not start from `pending`.
 *
 * IDEMPOTENT ON THE REVERSAL: the credit is keyed to the payout uuid and skipped
 * if it already exists, so a double `failed` cannot credit twice.
 *
 * @see docs/modules/Payment.md §8
 */
final class SettlePayoutAction extends BaseAction
{
    public function handle(mixed ...$arguments): Payout
    {
        /** @var Payout $payout */
        $payout = $arguments[0];
        /** @var PayoutStatus $outcome */
        $outcome = $arguments[1];
        /** @var int $settledBy */
        $settledBy = $arguments[2];
        /** @var string|null $detail */
        $detail = $arguments[3] ?? null;

        if (! $payout->status->canTransitionTo($outcome)) {
            // Already settled. Not an error worth a stack trace, but not a
            // silent no-op either: an admin clicking "paid" on a failed payout
            // has misunderstood something and should be told.
            throw PaymentException::payoutAlreadySettled($payout->uuid);
        }

        $payout->forceFill($outcome === PayoutStatus::Paid
            ? [
                'status' => PayoutStatus::Paid,
                // What the bank called it — free text, whatever the human was
                // given.
                'external_reference' => $detail,
                'settled_by' => $settledBy,
                'paid_at' => now(),
            ]
            : [
                'status' => PayoutStatus::Failed,
                'failure_reason' => $detail,
                'settled_by' => $settledBy,
                'failed_at' => now(),
            ])->save();

        if ($outcome === PayoutStatus::Failed) {
            $this->reverse($payout);
        }

        return $payout->refresh();
    }

    /**
     * Give the seller their balance back — the money never left.
     */
    private function reverse(Payout $payout): void
    {
        $alreadyReversed = SellerLedgerEntry::query()
            ->where('payout_uuid', $payout->uuid)
            ->where('type', LedgerEntryType::PayoutReversalCredit->value)
            ->exists();

        if ($alreadyReversed) {
            return;
        }

        SellerLedgerEntry::query()->create([
            'seller_org_uuid' => $payout->seller_org_uuid,
            'type' => LedgerEntryType::PayoutReversalCredit,
            'amount_minor' => LedgerEntryType::PayoutReversalCredit->signedAmount($payout->amount_minor),
            'payout_uuid' => $payout->uuid,
            'note' => $payout->failure_reason,
        ]);
    }
}
