<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Commands;

use App\Modules\Catalog\Application\Services\GoogleMerchantFeed;
use App\Modules\Catalog\Domain\Enums\FeedTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Build the Meta (Facebook/Instagram) product catalogue feed.
 *
 * **THE SAME BUILDER AS GOOGLE'S, WITH ONE FIELD CHANGED** (ADR-086,
 * BUILD_META_CATALOG_FEED.md): rows are identified by PRODUCT uuid, because
 * that is what the Pixel sends, and there are no item groups. Sharing the
 * builder is the point — the health, supplement and keyword exclusions are one
 * list, so the two catalogues cannot drift into submitting different things.
 *
 * Like its Google sibling this refuses to publish an empty feed over a good
 * one, and it is inert without the scheduler.
 */
final class BuildMetaCatalogFeedCommand extends Command
{
    protected $signature = 'feed:build-meta-catalog';

    protected $description = 'Build the Meta catalogue feed (product-level ids, for dynamic ads)';

    public function handle(GoogleMerchantFeed $feed): int
    {
        $report = $feed->build(FeedTarget::Meta);

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

        Log::info('Meta catalog feed built.', $report);

        /*
        | ZERO ITEMS IS A FAILURE, not a quiet success — the same rail the Google
        | feed has. An empty catalogue tells Meta this merchant sells nothing,
        | and every dynamic ad stops matching.
        */
        if (! $report['published']) {
            $this->error('Feed 0 item üretti — önceki dosya korundu.');

            return self::FAILURE;
        }

        $this->info(sprintf('%s — %d bayt.', $report['path'], $report['bytes']));

        return self::SUCCESS;
    }
}
