<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Commands;

use App\Modules\Catalog\Application\Services\GoogleMerchantFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rebuild the Google Merchant Center feed file.
 *
 * **THE DROP COUNTS ARE THE POINT OF THE OUTPUT, not decoration.** An item Google
 * rejects counts against the account, so the build refuses to submit one it knows
 * is bad — and then the only record of how much catalogue is being held back is
 * this report. "Boş açıklama" in particular IS the Turkish-copy backlog: nothing
 * else on the platform measures it.
 *
 * Logged as well as printed, because the nightly run has no reader.
 */
final class BuildGoogleMerchantFeedCommand extends Command
{
    protected $signature = 'feed:build-google-merchant';

    protected $description = 'Build the Google Merchant Center product feed from the sellable catalogue';

    public function handle(GoogleMerchantFeed $feed): int
    {
        $report = $feed->build();

        $this->table(['Ölçüm', 'Adet'], [
            ['Satılabilir ürün', $report['sellable']],
            ['Feed\'e yazılan', $report['written']],
            ['Ayıklandı — boş/zayıf açıklama', $report['dropped_no_description']],
            ['Ayıklandı — görsel yok', $report['dropped_no_image']],
            ['Ayıklandı — canlı teklif yok', $report['dropped_no_offer']],
            ['Ayıklandı — kapalı kategori', $report['dropped_excluded_category']],
            ['Ayıklandı — yasaklı kelime', $report['dropped_excluded_keyword']],
            ['GTIN\'siz (yazıldı)', $report['without_gtin']],
            ['Stokta değil (yazıldı)', $report['out_of_stock']],
        ]);

        /*
        | **THE KEYWORD DROPS ARE NAMED, NOT COUNTED.** A category rule removes
        | an aisle somebody chose; a keyword rule removes a product because of
        | how it was NAMED, and that is the one drop here that can be wrong
        | about an item Google would have taken. Printing the titles is what
        | makes a false positive reviewable instead of invisible.
        */
        if ($report['dropped_keyword_titles'] !== []) {
            $this->newLine();
            $this->warn('Yasaklı kelime nedeniyle elenenler:');

            foreach ($report['dropped_keyword_titles'] as $title) {
                $this->line('  - '.$title);
            }
        }

        Log::info('Google Merchant feed built.', $report);

        /*
        | ZERO ITEMS IS A FAILURE, not a quiet success.
        |
        | The service refuses to publish an empty feed over a good one; this is
        | the other half of that decision. Returning SUCCESS would have the
        | scheduler record a clean nightly run while the catalogue was silently
        | absent from Shopping — the exact shape of failure ADR-072 was written
        | about, where a task that does nothing looks identical to a task with
        | nothing to do.
        */
        if ($report['published'] === false) {
            $this->error('Feed YAYINLANMADI: yazılacak ürün yok. Önceki dosya korundu.');

            return self::FAILURE;
        }

        $this->info(sprintf('%s — %d bayt.', $report['path'], $report['bytes']));

        return self::SUCCESS;
    }
}
