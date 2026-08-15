<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Commands;

use App\Modules\Loyalty\Application\Actions\AwardPurchasePointsAction;
use Illuminate\Console\Command;

/**
 * The nightly purchase-points pass (ADR-083).
 *
 * **SCHEDULED, AND THAT IS PART OF THE FEATURE.** Without cron this command exists
 * and never runs, customers earn nothing for anything they bought, and no error is
 * raised anywhere — the exact failure ADR-072 was written about after eleven tasks
 * sat unscheduled on this server.
 *
 * Safe to run by hand at any time: the ledger's unique key makes a repeat a no-op.
 */
final class AwardPurchasePointsCommand extends Command
{
    protected $signature = 'loyalty:award-purchase-points';

    protected $description = 'Grant purchase points for delivered seller-orders past their return window';

    public function handle(AwardPurchasePointsAction $action): int
    {
        $result = $action->run(now());

        $this->info(sprintf(
            'Considered %d finalized seller-orders; granted %d (%d points).',
            $result['considered'],
            $result['granted'],
            $result['points'],
        ));

        return self::SUCCESS;
    }
}
