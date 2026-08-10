<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Imports;

use App\Modules\Catalog\Application\Import\CatalogRowImporter;
use App\Modules\Catalog\Domain\Models\Product;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;

/**
 * The upload surface for the bulk catalogue import (ADR-074).
 *
 * **IT OWNS THE COLUMNS AND NOTHING ELSE.** Every decision about what a row MEANS
 * — the category path, the brand, the KDV bracket, the lifecycle — lives in
 * `CatalogRowImporter`, which is testable without Filament and without a file.
 * This class is a mapping screen and a queue entry point.
 *
 * **THE THREE OVERRIDES BELOW ARE THE WHOLE TRICK, AND THEY ARE DELIBERATE.**
 * Filament's `Importer` assumes one row is one model: it resolves a record, fills
 * the mapped columns onto it and saves it. A catalogue row is not one model — it
 * is a category path, possibly a brand, a product, a variant and some images, all
 * of which must go through the authoring actions (ADR-074). So:
 *
 *   `resolveRecord()`  does the real work and returns the finished Product
 *   `fillRecord()`     does NOTHING — `baslik` is not a column on `products`, and
 *                      the default would set it as an attribute and then fail on
 *                      save
 *   `saveRecord()`     does NOTHING — the actions already committed, inside their
 *                      own transactions, and saving again would touch a model
 *                      three other objects have finished with
 *
 * The alternative the work order allowed — a bespoke queued page — was not taken:
 * Filament's importer brings chunking, the column-mapping UI, `failed_import_rows`
 * and the downloadable failure report for free, and all of it would have had to be
 * rebuilt.
 *
 * **A ROW THAT THROWS IS RECORDED AND SKIPPED**, which is the framework's own
 * behaviour and the reason `CatalogRowImporter` throws a Turkish sentence rather
 * than a class name: it is read by a human looking at a spreadsheet.
 *
 * @see App\Modules\Catalog\Application\Import\CatalogRowImporter
 * @see docs/modules/Catalog.md — bulk import
 */
final class ProductImporter extends Importer
{
    protected static ?string $model = Product::class;

    /**
     * **THE EXAMPLES ARE PART OF THE FEATURE, not decoration.** The column names
     * are Turkish and the category path has a syntax ("A > B > C") nobody guesses;
     * Filament builds the modal's downloadable example file out of them, so a
     * first upload has something to copy rather than something to fail.
     *
     * @return array<int, ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('baslik')
                ->label(__('catalog.import.column.title'))
                ->requiredMapping()
                ->example('Pamuklu Bisiklet Yaka Tişört')
                ->rules(['required', 'string', 'max:255']),

            /*
            | "Erkek > Giyim > Tişört". Required, because a product with nowhere to
            | live cannot be published — and guessing a category from a title is
            | exactly the kind of helpfulness that fills a catalogue with things
            | nobody can find.
            */
            ImportColumn::make('kategori_yolu')
                ->label(__('catalog.import.column.category_path'))
                ->requiredMapping()
                ->example('Erkek > Giyim > Tişört')
                ->rules(['required', 'string', 'max:500']),

            ImportColumn::make('marka')
                ->label(__('catalog.import.column.brand'))
                ->example('Örnek Marka')
                ->rules(['nullable', 'string', 'max:255']),

            /*
            | THE DEDUP KEY. Optional, because plenty of real products have no
            | barcode — but a row without one can never be corrected by re-upload,
            | which the column's help text says out loud.
            */
            ImportColumn::make('gtin')
                ->label(__('catalog.import.column.gtin'))
                ->example('08691234567890')
                ->rules(['nullable', 'string', 'max:14']),

            ImportColumn::make('aciklama')
                ->label(__('catalog.import.column.description'))
                ->example('%100 pamuk, regular fit.')
                ->rules(['nullable', 'string']),

            ImportColumn::make('kdv')
                ->label(__('catalog.import.column.tax'))
                ->example('%20')
                ->rules(['nullable', 'string', 'max:16']),

            ImportColumn::make('gorsel_url')
                ->label(__('catalog.import.column.images'))
                ->example('https://ornek.com/1.jpg | https://ornek.com/2.jpg')
                ->rules(['nullable', 'string']),
        ];
    }

    /**
     * The row, imported. @see the class note on why this method does the work.
     *
     * NEVER NULL, which is narrower than the parent's signature and deliberate:
     * returning null tells Filament to SKIP the row silently, and a row this
     * importer cannot handle should throw instead so it lands in
     * `failed_import_rows` with a reason a human can read.
     */
    public function resolveRecord(): Model
    {
        /** @var array<string, string|null> $row */
        $row = $this->data;

        return app(CatalogRowImporter::class)->import($row, (int) $this->import->user_id);
    }

    /**
     * NOTHING. @see the class note — `baslik` is not a column on `products`.
     */
    public function fillRecord(): void {}

    /**
     * NOTHING. The authoring actions already committed.
     */
    public function saveRecord(): void {}

    public static function getCompletedNotificationBody(Import $import): string
    {
        $failed = $import->getFailedRowsCount();

        return __('catalog.import.completed', [
            'imported' => number_format($import->successful_rows),
            'failed' => number_format($failed),
        ]);
    }

    /**
     * **THE IMPORT IS QUEUED AND IS INERT WITHOUT A WORKER** — the same
     * operational truth the sweeps carry. Left on the default connection's queue
     * rather than a bespoke one, so a single `queue:work` covers it; image
     * conversions go to Spatie's own `media` queue behind it.
     */
    public function getJobQueue(): ?string
    {
        return null;
    }
}
