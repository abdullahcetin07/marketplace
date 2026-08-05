<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Enums\PayoutStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payout;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Payment\Domain\Support\SellerBalance;

/**
 * Record that the platform is sending a seller their money (ADR-062,
 * Payment.md §8).
 *
 * **IT MOVES NO MONEY.** It writes down that an admin decided to, and debits the
 * seller's balance so the same lira cannot be promised twice. A human or a bank
 * makes the transfer; `MarkPayoutPaidAction` records that they did.
 *
 * THE DEBIT HAPPENS HERE, AT CREATION, NOT AT `paid`. That is the decision the
 * whole guard rests on: if the balance only moved when a payout was marked paid,
 * two admins could each create a payout for the whole balance, both would pass
 * their own check, and the seller would be overdrawn when both transfers went
 * through. Committing the balance when the DECISION is made closes that window.
 *
 * THE CONCURRENCY GUARD IS A ROW LOCK ON THE SELLER'S OWN LEDGER, taken before the
 * balance is read. Two simultaneous payouts for one seller must lock the same
 * rows, so the second blocks until the first has committed its debit and then
 * reads the reduced balance. A `SUM` cannot be locked, but the rows it sums can.
 *
 * ITS LIMIT, STATED RATHER THAN HIDDEN: a seller with NO ledger rows has nothing
 * to lock — and also a zero balance, so there is no payout to race over. Under
 * SQLite the lock is a no-op and the engine serialises writes anyway, which is
 * why the guard is also asserted through the balance itself rather than through
 * timing.
 *
 * A PAYOUT MAY NEVER EXCEED THE **PAYABLE** BALANCE — which stopped being the
 * whole balance when Shipping arrived (S3, ADR-064). A seller must not be paid for
 * goods the buyer can still send back, so money from an order that has not been
 * delivered long enough is ON HOLD: real, owed, and not yet drawable. @see
 * `SellerBalance` for the three figures and why an entry with no order is never
 * held.
 *
 * Refunds can still drive a balance negative afterwards (Payment.md §8) and that
 * is allowed; what is not allowed is the platform paying out money it does not
 * owe, or money it owes for a parcel the buyer may yet return.
 *
 * @see docs/modules/Payment.md §8
 */
final class CreatePayoutAction extends BaseAction
{
    public function __construct(private readonly CurrencyRepositoryContract $currencies) {}

    public function handle(mixed ...$arguments): Payout
    {
        /** @var string $sellerOrgUuid */
        $sellerOrgUuid = $arguments[0];
        /** @var int $amountMinor */
        $amountMinor = $arguments[1];
        /** @var int $createdBy */
        $createdBy = $arguments[2];
        /** @var string|null $note */
        $note = $arguments[3] ?? null;

        if ($amountMinor <= 0) {
            // A zero or negative payout would append a credit and pay the seller
            // by doing nothing. Refused before anything is locked.
            throw PaymentException::payoutAmountInvalid($amountMinor);
        }

        /*
        | THE LOCK, TAKEN BEFORE THE READ. `BaseAction` already wraps this in a
        | transaction, so these rows stay locked until the debit below is
        | committed — which is what makes the check-then-write atomic for one
        | seller. @see the class docblock for the limit.
        */
        SellerLedgerEntry::query()
            ->where('seller_org_uuid', $sellerOrgUuid)
            ->lockForUpdate()
            ->get(['id']);

        $balance = SellerBalance::for($sellerOrgUuid);

        if ($amountMinor > $balance->payableMinor) {
            /*
            | THE REFUSAL REPORTS WHAT IS PAYABLE, not what is owed. An admin told
            | "you have 12.000" who is then refused 12.000 learns nothing; one told
            | "8.000 payable, 4.000 still on hold" knows to wait rather than to
            | open a support ticket.
            */
            throw PaymentException::payoutExceedsBalance(
                $sellerOrgUuid,
                $amountMinor,
                $balance->payableMinor,
                $balance->onHoldMinor,
            );
        }

        $payout = Payout::query()->create([
            'seller_org_uuid' => $sellerOrgUuid,
            'amount_minor' => $amountMinor,
            'currency_id' => $this->currencies->default()->getKey(),
            'status' => PayoutStatus::Pending,
            'note' => $note,
            'created_by' => $createdBy,
        ]);

        /*
        | The balance moves NOW. `LedgerEntryType` points the sign, so this passes
        | a magnitude and cannot accidentally credit the seller.
        */
        SellerLedgerEntry::query()->create([
            'seller_org_uuid' => $sellerOrgUuid,
            'type' => LedgerEntryType::PayoutDebit,
            'amount_minor' => LedgerEntryType::PayoutDebit->signedAmount($amountMinor),
            'payout_uuid' => $payout->uuid,
        ]);

        return $payout;
    }
}
