<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Listeners;

use App\Modules\Offer\Application\Jobs\ZeroOffersMissingFromFeedJob;
use App\Modules\Offer\Presentation\Filament\Seller\Imports\OfferImporter;
use Throwable;

/**
 * A finished full-sync upload becomes one sweep (Offer.md §19).
 *
 * **IT DECIDES NOTHING AND QUEUES EVERYTHING.** Filament fires this inside the
 * batch's `finally()`, on whichever worker happened to run the last chunk, and
 * the sweep can touch thousands of offers through the stock action. So this
 * checks three cheap things — our importer, the seller's own tick, an import that
 * actually processed rows — and hands the work to the queue.
 *
 * **IT NEVER BREAKS THE IMPORT.** Anything that escapes here escapes into
 * Filament's completion handler, which would cost the seller their notification
 * and their failure report for work that already succeeded.
 */
final class SweepOffersMissingFromFeed
{
    public function handle(object $event): void
    {
        try {
            $import = $event->getImport();

            if ($import->importer !== OfferImporter::class) {
                return;
            }

            if (($event->getOptions()['zero_missing'] ?? false) !== true) {
                return;
            }

            ZeroOffersMissingFromFeedJob::dispatch((int) $import->getKey());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
