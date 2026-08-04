<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Listeners;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Events\PaymentSucceeded;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A paid order becomes two rows in the seller's ledger (ADR-062, Payment.md §7).
 *
 * PER SELLER, BECAUSE A BASKET IS PER SELLER. One card paid for N orders (ADR-052);
 * each belongs to a different merchant, earns them a different amount and owes the
 * platform a different commission. So the fan-out here is the mirror of the
 * checkout split — Payment rejoined the basket to charge it, and this splits it
 * again to settle it.
 *
 * TWO ENTRIES, NOT ONE NET FIGURE. "You earned 129,90 and we took 23,38" is a
 * sentence a seller can check; "you earned 106,52" is one they can only accept.
 * The sign of each is decided by `LedgerEntryType`, so this method passes
 * magnitudes and cannot get the direction wrong.
 *
 * IT USES THE FROZEN COMMISSION, IT DOES NOT RECOMPUTE IT. Payment owns the rules
 * and Order froze the result onto the lines a moment ago (ADR-061); reading it
 * back is what guarantees the ledger and the order agree to the kuruş, forever.
 * Resolving a second time would be two computations of one number, which is the
 * thing P2's single rounding helper exists to prevent.
 *
 * WHICH MEANS IT DEPENDS ON ORDER'S LISTENER HAVING RUN, and that is stated rather
 * than hidden. Both subscribe to `PaymentSucceeded`; Laravel calls listeners in
 * registration order, and `OrderServiceProvider` boots before
 * `PaymentServiceProvider` (`bootstrap/providers.php`), so the freeze is already
 * done. If it were ever not, `commission_minor` comes back null and this SKIPS the
 * order and logs — crediting a seller their full sale with no commission taken is
 * the expensive way to be wrong, and a missing entry is recoverable while a wrong
 * one is an argument.
 *
 * IDEMPOTENT TWICE OVER. PayTR retries a callback until it hears "OK", so this may
 * run repeatedly for one payment: the check below skips what is already recorded,
 * and `(payment_uuid, order_uuid, type)` is UNIQUE so a race loses rather than
 * double-crediting. The exception that produces is caught and treated as "somebody
 * else already did it", because that is exactly what it means.
 *
 * IT RUNS AFTER COMMIT, since the event is dispatched from `BaseAction::after()`.
 * A ledger entry for a payment a later failure rolled back would be an accounting
 * record of money that never arrived — the difference between a ledger and a lie.
 * The cost is the mirror image: a failure HERE leaves a collected payment
 * uncredited, which is why it logs loudly and why every entry is rebuildable from
 * the order it names.
 *
 * @see docs/modules/Payment.md §7
 */
final class CreditSellerLedger
{
    public function __construct(private readonly OrderQueryContract $orders) {}

    public function handle(PaymentSucceeded $event): void
    {
        foreach ($event->orderUuids as $orderUuid) {
            $this->credit($event->paymentUuid, $orderUuid);
        }
    }

    private function credit(string $paymentUuid, string $orderUuid): void
    {
        $settlement = $this->orders->orderSettlement($orderUuid);
        $totals = $this->orders->orderTotals($orderUuid);

        if ($settlement === null || $totals === null) {
            Log::channel('errors')->error('Cannot credit a seller for an order that does not resolve', [
                'payment_uuid' => $paymentUuid,
                'order_uuid' => $orderUuid,
            ]);

            return;
        }

        if ($settlement['commission_minor'] === null) {
            /*
            | UNSETTLED, NOT ZERO. Crediting the full sale with no commission taken
            | would silently overpay the seller, and the platform would find out
            | at payout. A missing pair of entries is recoverable; a wrong one is
            | an argument with a merchant.
            */
            Log::channel('errors')->error('A paid order had no frozen commission; seller not credited', [
                'payment_uuid' => $paymentUuid,
                'order_uuid' => $orderUuid,
            ]);

            return;
        }

        $seller = $settlement['selling_org_uuid'];

        // Already recorded — a retried callback, which is the ordinary case.
        if (SellerLedgerEntry::query()
            ->where('payment_uuid', $paymentUuid)
            ->where('order_uuid', $orderUuid)
            ->exists()
        ) {
            return;
        }

        try {
            DB::transaction(function () use ($seller, $paymentUuid, $orderUuid, $totals, $settlement): void {
                // What the seller earned: the order's KDV-INCLUSIVE total, which is
                // the gross the buyer paid (Payment.md §7).
                $this->append($seller, LedgerEntryType::SaleCredit, $totals['grand_total_minor'], $paymentUuid, $orderUuid);

                // …and the platform's share of it, frozen at payment (ADR-061).
                $this->append($seller, LedgerEntryType::CommissionDebit, (int) $settlement['commission_minor'], $paymentUuid, $orderUuid);
            });
        } catch (UniqueConstraintViolationException) {
            // Two callbacks raced. The index did its job; the other one won.
        }
    }

    /**
     * `$magnitudeMinor` is always positive — `LedgerEntryType` decides the sign, so
     * no call site can append a positive commission and pay the seller the
     * platform's cut.
     */
    private function append(
        string $sellerOrgUuid,
        LedgerEntryType $type,
        int $magnitudeMinor,
        string $paymentUuid,
        string $orderUuid,
    ): void {
        SellerLedgerEntry::query()->create([
            'seller_org_uuid' => $sellerOrgUuid,
            'type' => $type,
            'amount_minor' => $type->signedAmount($magnitudeMinor),
            'order_uuid' => $orderUuid,
            'payment_uuid' => $paymentUuid,
        ]);
    }
}
