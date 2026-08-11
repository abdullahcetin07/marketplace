<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Import;

use Filament\Actions\Imports\Jobs\ImportCsv;

/**
 * Filament's import chunk job, with a ceiling on how often it may fail (ADR-075,
 * Fix B).
 *
 * **A SUBCLASS BECAUSE `Importer` HAS NO HOOK FOR THIS.** Filament 3.3 exposes
 * `getJobQueue()`, `getJobConnection()`, `getJobRetryUntil()`, `getJobMiddleware()`,
 * `getJobTags()` and `getJobBatchName()` — and nothing for `$tries` or `$backoff`.
 * The lever it does give is `ImportAction::job(...)`, which swaps the class
 * outright, so the ceiling lives here.
 *
 * **WHY IT WAS NEEDED.** Vendor `ImportCsv` collects any non-row-level `Throwable`
 * and rethrows it from `handleExceptions()`, failing the whole job. With no
 * `$tries`, no `$backoff` and a 24-hour `retryUntil`, one rejected catalogue row
 * turned into **29,074 attempts** and ~155,000 duplicate failure rows overnight —
 * each attempt re-running the entire chunk.
 *
 * **THE ROOT FIX IS ELSEWHERE AND THIS IS STILL WORTH HAVING.**
 * `ProductImporter::resolveRecord()` now translates a domain refusal into
 * `RowImportFailedException`, which Filament records per row without failing the
 * job — so this ceiling should never be reached in normal operation. It exists for
 * the defect nobody has written yet: a bad row must never again be able to cost
 * five figures of retries.
 *
 * THE BACKOFF GROWS, so a genuinely transient failure (a database blip, a locked
 * row) still gets its second and third chance, spread far enough apart to be worth
 * taking.
 *
 * @see App\Modules\Catalog\Presentation\Filament\Imports\ProductImporter
 */
final class ImportChunk extends ImportCsv
{
    /**
     * Three attempts, then the job is done trying.
     */
    public int $tries = 3;

    /**
     * Seconds between attempts: half a minute, two minutes, five.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];
}
