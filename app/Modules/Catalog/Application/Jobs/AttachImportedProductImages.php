<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Jobs;

use App\Core\Application\Jobs\BaseJob;
use App\Modules\Catalog\Domain\Models\Product;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One product's catalogue-import images, fetched off the import chunk (ADR-074).
 *
 * **THE DOWNLOAD USED TO HAPPEN INSIDE THE CHUNK, AND THAT IS WHY LARGE IMPORTS
 * NEVER FINISHED.** Filament sends 100 rows per chunk and each row carries ~1.6
 * image urls, so one chunk meant ~160 sequential HTTP fetches — against a worker
 * timeout of 120 seconds. The worker killed the job mid-chunk, the retry hit the
 * same wall, and `tries` ran out: 385 `MaxAttemptsExceeded` failures in seventeen
 * minutes across two uploads, and a 21,205-row file that reported exactly 2,000
 * successes — twenty whole chunks, the ones that happened to fit.
 *
 * The two symptoms that made it hard to read are worth recording. `failed_import_rows`
 * stayed EMPTY, because the rows never failed validation — the job died before it
 * could record anything. And the import reported far fewer successes than it had
 * actually written, because Filament increments `successful_rows` when a chunk
 * FINISHES: everything a killed chunk had already committed was invisible to its
 * own report.
 *
 * **SO THE CHUNK NOW DOES DATABASE WORK ONLY** — category path, brand, product,
 * variant, tax bracket — which is fast and bounded, and the network work rides
 * here, one job per product, on the `media` queue whose supervisor already allows
 * ten minutes and 512 MB. That is what `ProductImporter`'s own docblock always
 * claimed happened.
 *
 * **A BAD IMAGE STILL NEVER FAILS A PRODUCT.** A 404, a redirect to an HTML error
 * page, a host that hangs — each is logged with its url and the next url is tried.
 * The product is already in the catalogue by the time this runs; it simply has
 * fewer photos than the file promised.
 *
 * **IT RE-CHECKS THE COLLECTION BEFORE FETCHING**, because a retry of this job
 * must not stack a second copy of the same photograph onto a product.
 *
 * @see App\Modules\Catalog\Application\Import\CatalogRowImporter
 */
final class AttachImportedProductImages extends BaseJob
{
    /**
     * **UNDER `retry_after`, NOT UNDER THE SUPERVISOR'S TIMEOUT.** The `media`
     * supervisor allows ten minutes, but the redis connection releases a reserved
     * job back to the queue after 180 seconds — so a job permitted to run longer
     * than that gets handed to a SECOND worker while the first is still
     * downloading, and the same product collects two copies of every photograph.
     * `config/queue.php` states the rule; this is the job that has to live inside
     * it. 170 seconds is generous for one product's handful of urls, which is all
     * this job ever holds.
     */
    public int $timeout = 170;

    /**
     * @param array<int, string> $urls
     */
    public function __construct(
        private readonly int $productId,
        private readonly array $urls,
    ) {
        parent::__construct();

        $this->onQueue('media');
    }

    /**
     * **TWELVE HOURS, BECAUSE THIS JOB'S WAIT IS UNBOUNDED BY DESIGN.**
     * `BaseJob` sets an hour, which is right for a job dispatched by a request and
     * expected to run promptly. A bulk import pushes ONE OF THESE PER PRODUCT —
     * 19,886 of them from a single upload — onto a queue with four workers, so the
     * last one legitimately waits most of a day for its turn.
     *
     * `retryUntil` is stamped into the payload at DISPATCH and Laravel checks it
     * BEFORE it checks attempts, so an expired job is failed on pickup having done
     * nothing: 963 of them died that way with `attempts = 0`, and **3,961 products
     * — one in five — ended up with no photographs at all** while every other
     * number in the import report said success. Third instance of the same trap in
     * this feature (@see `ProductImporter::getJobRetryUntil()`), and the lesson is
     * the same: a deadline measured from dispatch has to outlast the QUEUE, not
     * the job.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(): void
    {
        $product = Product::query()->find($this->productId);

        if (! $product instanceof Product) {
            return;
        }

        // NEW IMAGES ONLY WHEN THERE ARE NONE — the guard the importer used to
        // hold, moved here with the work it protects. Re-running an import must
        // not stack a fourth copy of the same photo onto a product.
        if ($product->getMedia('images')->isNotEmpty()) {
            return;
        }

        foreach ($this->urls as $url) {
            try {
                $product->addMediaFromUrl($url)->toMediaCollection('images');
            } catch (Throwable $exception) {
                Log::channel('errors')->warning('Could not fetch an image during a catalogue import', [
                    'product_uuid' => $product->uuid,
                    'url' => $url,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
