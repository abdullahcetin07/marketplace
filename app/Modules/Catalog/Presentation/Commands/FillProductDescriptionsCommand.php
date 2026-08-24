<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Commands;

use App\Modules\Catalog\Application\Services\DescriptionBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fill empty product descriptions from category templates (ADR-088).
 *
 * A ONE-OFF MAINTENANCE COMMAND, not a schedule. It is idempotent — a second run
 * finds nothing empty — so re-running after an import is free, but nothing about
 * it needs to happen nightly and a scheduled entry would be a job that does
 * nothing every day for the rest of the platform's life.
 *
 * `--dry-run` reports without writing, because the first question anybody asks
 * about a command that touches seven thousand live rows is "what would it do".
 */
final class FillProductDescriptionsCommand extends Command
{
    protected $signature = 'catalog:fill-descriptions
                            {--limit= : Stop after filling this many products}
                            {--chunk=200 : Products loaded per batch}
                            {--dry-run : Report what would be written, and write nothing}';

    protected $description = 'Generate a template description for every published product that has none';

    public function handle(DescriptionBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

        $report = $backfill->run(
            chunkSize: max(1, (int) $this->option('chunk')),
            limit: $limit,
            dryRun: $dryRun,
        );

        $this->table(['Ölçüm', 'Adet'], [
            ['İncelenen yayındaki ürün', $report['considered']],
            [$dryRun ? 'Doldurulacak' : 'Dolduruldu', $report['filled']],
            ['Atlandı — açıklaması zaten var', $report['skipped_has_description']],
            ['Atlandı — anlatacak veri yok', $report['skipped_undescribable']],
        ]);

        if ($dryRun) {
            $this->warn('Dry run — hiçbir şey yazılmadı.');
        }

        Log::info('Product descriptions backfilled.', $report + ['dry_run' => $dryRun]);

        return self::SUCCESS;
    }
}
