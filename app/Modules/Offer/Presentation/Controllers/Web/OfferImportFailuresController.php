<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Controllers\Web;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Modules\Offer\Presentation\Filament\Seller\Imports\OfferImporter;
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use League\Csv\Bom;
use League\Csv\Writer;
use SplTempFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Raporu indir" on Yükleme Geçmişi — the failed rows of one offer-feed import.
 *
 * **IT EXISTS BECAUSE FILAMENT'S OWN DOWNLOAD ANSWERS THE WRONG QUESTION.** The
 * vendor route allows the row's UPLOADER and nobody else, while the history page
 * lists the SHOP's uploads (OfferImports, 2026-08-14): an owner could see that
 * their employee's file failed 1,746 rows and could not open the report saying
 * why. Whoever may see the row may take the report — the owner's rule, and the
 * only one that makes the page coherent.
 *
 * **IT IS ALSO THE ONLY WAY TO RESOLVE THE ACTOR CORRECTLY.** Registering a
 * policy for the vendor model would have been less code and would have broken the
 * seller: that controller resolves the user through `Filament::auth()`, which off
 * a panel route falls back to the DEFAULT panel — admin — and a signed-in seller
 * reads as nobody. This route names the seller guard.
 *
 * **THE SCOPE IS COPIED FROM THE PAGE, DELIBERATELY**: active members of the
 * organizations the actor belongs to, and offer-feed imports only. A seller must
 * not be able to pull an admin's catalogue-import report (ADR-074) by guessing an
 * id, so the importer is checked as well as the uploader.
 *
 * **ABSENCE IS A 404, NOT A 403.** Somebody else's import and a deleted one
 * answer identically; telling them apart confirms which ids exist.
 *
 * **DEVIATION (non-negotiable #7): the URL carries the internal id.** Filament's
 * `imports` table is a vendor table with no uuid column, and adding one to a table
 * the package writes is a larger change than this fix deserves. It is bounded: the
 * surface is authenticated, seller-guarded and scoped to the actor's own shop.
 *
 * @see App\Modules\Offer\Presentation\Filament\Seller\Pages\OfferImports
 */
final class OfferImportFailuresController
{
    public function __construct(
        private readonly OrganizationAuthorizationContract $authz,
    ) {}

    public function __invoke(Import $import): StreamedResponse
    {
        if (! $this->readableByActor($import)) {
            throw new NotFoundHttpException;
        }

        $csv = Writer::createFromFileObject(new SplTempFileObject);
        $csv->setOutputBOM(Bom::Utf8);

        $first = $import->failedRows()->first();

        $headers = $first instanceof FailedImportRow ? array_keys($first->data) : [];
        $headers[] = __('offer.imports.failure_reason');

        $csv->insertOne($headers);

        // Chunked rather than loaded: a failed feed is thousands of rows, and the
        // report exists precisely for the imports with the most of them.
        $import->failedRows()->lazyById(100)->each(function (Model $row) use ($csv): void {
            /** @var FailedImportRow $row */
            $csv->insertOne([
                ...$row->data,
                'error' => $row->validation_error ?? __('offer.imports.unknown_reason'),
            ]);
        });

        $name = Str::of((string) $import->file_name)->beforeLast('.')->remove('.')->slug()->value();

        return response()->streamDownload(
            function () use ($csv): void {
                foreach ($csv->chunk(1000) as $chunk) {
                    echo $chunk;
                }
            },
            'hatali-satirlar-'.$import->getKey().'-'.$name.'.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * The same question the history page asks of every row it renders.
     */
    private function readableByActor(Import $import): bool
    {
        if ($import->importer !== OfferImporter::class) {
            return false;
        }

        $actorId = (int) current_actor()?->getKey();

        if ($actorId === 0) {
            return false;
        }

        if ((int) $import->user_id === $actorId) {
            return true;
        }

        foreach ($this->authz->organizationIdsForUser($actorId) as $organizationId) {
            foreach ($this->authz->activeMemberUserIdsFor($organizationId) as $memberId) {
                if ((int) $memberId === (int) $import->user_id) {
                    return true;
                }
            }
        }

        return false;
    }
}
