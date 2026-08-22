<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Commands;

use App\Modules\Reviews\Application\Services\ReviewInvitationSweep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nightly: ask buyers about what arrived (ADR-087).
 *
 * **INERT WITHOUT THE SCHEDULER** — the ADR-072 lesson, and this one fails in the
 * quietest direction of all: no error, no queue backlog, simply no reviews ever
 * arriving, which looks exactly like customers who did not feel like writing one.
 * The counts below are what distinguishes those two, so they are logged as well
 * as printed: nobody reads a nightly command's stdout.
 */
final class RequestPendingReviewsCommand extends Command
{
    protected $signature = 'reviews:request-pending {--limit=500 : Maximum invitations to consider in one run}';

    protected $description = 'Invite buyers to review purchases delivered long enough ago';

    public function handle(ReviewInvitationSweep $sweep): int
    {
        $report = $sweep->run(limit: max(1, (int) $this->option('limit')));

        $this->table(['Ölçüm', 'Adet'], [
            ['Teslim edilmiş uygun satır', $report['eligible']],
            ['Davet gönderildi', $report['invited']],
            ['Zaten değerlendirilmiş', $report['already_reviewed']],
            ['Zaten davet edilmiş', $report['already_asked']],
            ['Bildirim kapalı (bastırıldı)', $report['suppressed']],
            ['Müşterisi bulunamadı', $report['no_customer']],
        ]);

        Log::info('Review invitations swept.', $report);

        return self::SUCCESS;
    }
}
