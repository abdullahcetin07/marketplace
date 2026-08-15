<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Actions;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Loyalty\Domain\DTOs\GrantPointsDTO;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use Carbon\CarbonInterface;

/**
 * Pay points for purchases that can no longer be undone (ADR-083).
 *
 * **A SWEEP RATHER THAN A LISTENER, BECAUSE NOTHING EMITS "IT IS NOW SAFE".** The
 * moment that matters is `delivered_at + return_days` — a date passing, not
 * something happening — and the platform already answers that shape of question
 * with a scheduled pass (order expiry, ADR-072; auto-payout, ADR-064).
 *
 * **IT IS INERT WITHOUT CRON**, which is the recurring lesson: a sweep nobody
 * scheduled is indistinguishable from a sweep with nothing to do, and this one is
 * money-shaped. The schedule entry is part of the feature, not part of the deploy.
 *
 * **RE-RUNNING IT IS FREE.** The reader hands back every eligible seller-order
 * every night, including the ones already paid; the ledger's unique key turns the
 * repeat into a no-op (ADR-081). That is deliberate — a reader that filtered on
 * Loyalty's table would be Order reaching into Loyalty to answer a question about
 * orders.
 *
 * **NOT A `BaseAction` TRANSACTION AROUND THE WHOLE PASS.** Each grant owns its
 * own; wrapping a thousand orders in one would mean a single bad row costs the
 * night's work, and there is nothing to roll back TO — the grants are independent.
 *
 * @see App\Modules\Loyalty\Presentation\Commands\AwardPurchasePointsCommand
 */
final class AwardPurchasePointsAction
{
    public function __construct(
        private readonly OrderQueryContract $orders,
        private readonly GrantPointsAction $grant,
    ) {}

    /**
     * @return array{considered: int, granted: int, points: int}
     */
    public function run(CarbonInterface $asOf): array
    {
        if (! settings('loyalty.enabled', true)) {
            return ['considered' => 0, 'granted' => 0, 'points' => 0];
        }

        $rate = (float) settings('loyalty.earn.purchase_rate', 1);

        $eligible = $this->orders->pointsEligibleSellerOrders($asOf);

        $granted = 0;
        $points = 0;

        foreach ($eligible as $order) {
            /*
            | **FLOORED, ON WHOLE LIRA.** `paid_minor` is kuruş; points are a count
            | of a thing a customer can hold, so a 149,90 TL order at one point per
            | lira earns 149 and not 149,9 of anything. Rounding up would let a
            | basket split into two orders earn more than the same basket did not.
            */
            $earned = (int) floor(((int) $order['paid_minor']) / 100 * $rate);

            $entry = $this->grant->run(new GrantPointsDTO(
                customerUuid: (string) $order['customer_uuid'],
                points: $earned,
                source: LoyaltyPointSource::Purchase,
                sourceUuid: (string) $order['order_uuid'],
                /*
                | THE RATE THAT PRODUCED IT, KEPT. A rate change is not retroactive
                | (ADR-082), so months later the only way to explain a row is to
                | have written down what it was computed with.
                */
                meta: [
                    'rule' => 'purchase',
                    'rate' => $rate,
                    'paid_minor' => (int) $order['paid_minor'],
                    'currency' => (string) $order['currency_code'],
                ],
            ));

            if ($entry !== null) {
                $granted++;
                $points += $earned;
            }
        }

        return ['considered' => count($eligible), 'granted' => $granted, 'points' => $points];
    }
}
