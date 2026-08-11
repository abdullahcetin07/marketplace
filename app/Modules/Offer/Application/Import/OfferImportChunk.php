<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Import;

use Filament\Actions\Imports\Jobs\ImportCsv;

/**
 * The seller feed's chunk job, with a ceiling on how often it may fail
 * (ADR-076, and the ADR-075 lesson applied before it can be paid for twice).
 *
 * **A SUBCLASS BECAUSE `Importer` HAS NO HOOK FOR THIS.** Filament 3.3 exposes
 * `getJobQueue()`, `getJobConnection()`, `getJobRetryUntil()`, `getJobMiddleware()`
 * and `getJobTags()` — nothing for `$tries` or `$backoff`. `ImportAction::job()`
 * swaps the class, so the ceiling lives here.
 *
 * **THE ROOT FIX IS ELSEWHERE AND THIS IS STILL WORTH HAVING.**
 * `OfferImporter::resolveRecord()` translates a rejected row into
 * `RowImportFailedException`, which Filament records per row without failing the
 * job — so this should never be reached in normal operation. It is here for the
 * defect nobody has written yet, because the catalogue import taught what an
 * unbounded retry costs: 29,074 attempts and ~155,000 duplicate failure rows
 * between one evening and the next morning.
 *
 * @see App\Modules\Offer\Presentation\Filament\Seller\Imports\OfferImporter
 */
final class OfferImportChunk extends ImportCsv
{
    public int $tries = 3;

    /**
     * Seconds between attempts: half a minute, two minutes, five.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];
}
