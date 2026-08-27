<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Commands;

use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Support\TurkishFold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild `products.search_text` — the folded haystack the text search matches.
 *
 * **THE BACKFILL AND THE REPAIR, in the shape `catalog:refresh-sellability`
 * already established (ADR-079).** The column ships NULL, so until this has run
 * once nothing matches any query at all; and it drifts afterwards for a reason
 * the model hook cannot see — renaming a BRAND changes the
 * haystack of every product that carries it, and nothing tells the product row.
 *
 * **IT WRITES THE COLUMN DIRECTLY, and that is not the ADR-074/076 rule being
 * broken.** That rule is about DOMAIN state: a product's title, its category, its
 * moderation status — write those behind an action's back and the events other
 * modules depend on never fire. This is a derived string nobody subscribes to
 * and Scout does not index. Saving 20,000 models to recompute it would fire
 * 20,000 search re-index jobs to change nothing in the index.
 */
final class RefreshSearchTextCommand extends Command
{
    /** Rows per chunk. Large enough to be few queries, small enough to hold. */
    private const CHUNK = 500;

    protected $signature = 'catalog:refresh-search-text';

    protected $description = 'Rebuild the folded products.search_text haystack from titles, brand and description';

    public function handle(): int
    {
        $changed = 0;
        $total = 0;

        Product::query()
            ->withTrashed()
            // Loaded, not lazily reached: the haystack needs the brand name and
            // strict mode throws on a lazy load — with 20,000 rows it would also
            // be 20,000 extra queries.
            ->with(['brand:id,name'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($products) use (&$changed, &$total): void {
                foreach ($products as $product) {
                    $total++;

                    $haystack = TurkishFold::haystack([
                        $product->title_tr,
                        $product->title_en,
                        $product->brand?->name,
                        $product->description_tr,
                        $product->description_en,
                    ]);

                    if ($haystack === $product->search_text) {
                        continue;
                    }

                    DB::table('products')
                        ->where('id', $product->getKey())
                        ->update(['search_text' => $haystack]);

                    $changed++;
                }
            });

        $this->info(sprintf('Folded %d products (%d rows changed).', $total, $changed));

        return self::SUCCESS;
    }
}
