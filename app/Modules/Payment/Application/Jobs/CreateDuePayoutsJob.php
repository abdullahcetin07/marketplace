<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Core\Application\Jobs\BaseJob;
use App\Modules\Payment\Application\Actions\CreatePayoutAction;
use App\Modules\Payment\Domain\Enums\PayoutStatus;
use App\Modules\Payment\Domain\Models\Payout;
use App\Modules\Payment\Domain\Models\SettlementWindow;
use App\Modules\Payment\Domain\Support\SellerBalance;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Propose one payout per seller, for everything that has become payable
 * (owner decision, 2026-08-06; ADR-062 §8, ADR-064).
 *
 * **THE DECISION IS AUTOMATED; THE BANK IS NOT.** This writes a `pending` payout
 * saying "the platform owes this seller this much and the hold has expired". A
 * human still makes the transfer and marks it paid — the software moves no money
 * (ADR-062), and nothing here has changed that.
 *
 * ONE PAYOUT PER SELLER, NOT ONE PER ORDER, and that is the whole shape of the
 * job. A payout is a bank transfer somebody executes by hand; one per delivered
 * order would fragment a seller's month into dozens of tiny transfers and make the
 * finance team's work proportional to the platform's order count. So the amount is
 * the seller's whole PAYABLE balance — every order whose hold has expired, summed
 * by the ledger rather than by this job.
 *
 * **IT REUSES `CreatePayoutAction` RATHER THAN WRITING ROWS.** Everything that
 * makes a payout safe lives in that action: the row lock on the seller's ledger,
 * the payable ceiling, the `payout_debit` that stops the same lira being promised
 * twice. A job that inserted `payouts` directly would be a second, quieter path to
 * moving money — the exact duplication the platform avoids everywhere else.
 *
 * THREE THINGS STOP A DOUBLE PAYOUT, and only the first is this job's own:
 *
 *   1. A seller who already has a PENDING payout is skipped. Two open transfers
 *      for one seller is a reconciliation problem for a human, whatever the
 *      arithmetic says.
 *   2. Creating a payout appends `payout_debit` immediately (P4), so the payable
 *      balance falls to zero and a second run finds nothing to propose.
 *   3. The action's row lock serialises two runs that somehow overlap.
 *
 * BOUNDED AND FAILURE-ISOLATED, like every sweep here. One seller whose payout
 * throws must not strand the rest; the failure is logged and the next run tries
 * again, which is also why running it more often than daily is harmless.
 *
 * IT LOOKS ONLY AT SELLERS WITH AN EXPIRED WINDOW. "Every seller on the platform"
 * is an unbounded scan of a table that only grows; the sellers who could possibly
 * be payable today are exactly those with a delivery whose hold has passed.
 *
 * @see App\Modules\Payment\Domain\Support\SellerBalance
 * @see docs/modules/Payment.md §8
 */
final class CreateDuePayoutsJob extends BaseJob
{
    /**
     * How many sellers one run will propose payouts for.
     *
     * A cap, not a target: the work is idempotent and the remainder waits for
     * tomorrow rather than holding one long run open.
     */
    private const int BATCH = 200;

    public function __construct()
    {
        // BaseJob subclasses must call this — the surprise documented in
        // CLAUDE.md.
        parent::__construct();
    }

    public function handle(CreatePayoutAction $payouts): void
    {
        $created = 0;
        $totalMinor = 0;

        foreach ($this->candidates() as $sellerOrgUuid) {
            $amount = $this->propose($sellerOrgUuid, $payouts);

            if ($amount > 0) {
                $created++;
                $totalMinor += $amount;
            }
        }

        if ($created === 0) {
            return;
        }

        Log::channel('audit')->info('Proposed automatic payouts', [
            'payouts' => $created,
            'total_minor' => $totalMinor,
        ]);
    }

    /**
     * The sellers who could possibly be payable today.
     *
     * DISTINCT SELLERS WITH AN EXPIRED HOLD — not every seller, and not every
     * window. A seller with fifty delivered orders is one candidate and will get
     * one payout for all of them.
     *
     * @return array<int, string>
     */
    private function candidates(): array
    {
        return SettlementWindow::query()
            ->payable()
            ->distinct()
            ->limit(self::BATCH)
            ->pluck('seller_org_uuid')
            ->all();
    }

    /**
     * One seller, on their own, so a single failure does not strand the rest.
     *
     * @return int the amount proposed, or 0 when nothing was
     */
    private function propose(string $sellerOrgUuid, CreatePayoutAction $payouts): int
    {
        /*
        | GUARD ONE: an open transfer already exists. The arithmetic below would
        | usually refuse anyway — the first payout's debit took the balance down —
        | but "usually" is not what this needs to be. Two pending payouts for one
        | seller is a reconciliation problem for whoever reads the bank statement,
        | and the cheapest place to prevent it is before proposing a second.
        */
        if (Payout::query()->forSeller($sellerOrgUuid)->where('status', PayoutStatus::Pending->value)->exists()) {
            return 0;
        }

        $payable = SellerBalance::for($sellerOrgUuid)->payableMinor;

        if ($payable <= 0) {
            // Delivered, but already paid out — or refunded back to nothing.
            return 0;
        }

        try {
            $payouts->run($sellerOrgUuid, $payable, null);

            return $payable;
        } catch (Throwable $exception) {
            /*
             * Logged, not rethrown. Rethrowing would fail the whole batch and
             * retry it — re-proposing payouts that already succeeded, which the
             * pending guard would then refuse, and stopping at the same bad seller
             * every night. Tomorrow's run tries this one again.
             */
            Log::channel('errors')->warning('Could not propose an automatic payout', [
                'seller_org_uuid' => $sellerOrgUuid,
                'payable_minor' => $payable,
                'exception' => $exception->getMessage(),
            ]);

            return 0;
        }
    }
}
