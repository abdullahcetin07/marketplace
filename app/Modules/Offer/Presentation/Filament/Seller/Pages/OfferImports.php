<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Filament\Seller\Pages;

use App\Modules\Offer\Presentation\Filament\Seller\Imports\OfferImporter;
use Filament\Actions\Imports\Models\Import;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Yükleme Geçmişi" — where a seller finds out what their CSV actually did.
 *
 * **A COMPLETION TOAST IS NOT A REPORT.** Filament announces an import once, in a
 * notification that scrolls away; a seller who uploaded 3,525 rows and was told
 * 3,413 failed has no way back to WHY. This page is that way back, and it exists
 * because the answer is usually actionable — "bu barkod katalogda yok" means the
 * product has to be added before any price can attach to it.
 *
 * **THE REASONS ARE GROUPED, BECAUSE 3,413 ROWS ARE NOT 3,413 PROBLEMS.** In
 * practice a failed feed has two or three distinct causes; a flat list buries that
 * under a scroll bar. The breakdown is one `GROUP BY` and it is the whole point of
 * the detail modal — the sample rows below it are there to make a reason concrete,
 * not to be read end to end. The full set is the CSV.
 *
 * **IT SHOWS THE UPLOADER'S OWN IMPORTS AND NOTHING ELSE.** Scoped by `user_id`
 * to the signed-in seller and by `importer` to the offer feed, so neither another
 * merchant's uploads nor the admin's catalogue imports (ADR-074) can appear here.
 *
 * **AN IMPORT WITH NO `completed_at` IS STILL RUNNING — OR DIED TRYING.** Both
 * read as "sürüyor", which is honest: the queue is the only thing that knows, and
 * a job that exhausted its retries never gets to say so. That is exactly what the
 * seller saw before the importer stopped letting exceptions escape a row.
 *
 * @see App\Modules\Offer\Presentation\Filament\Seller\Imports\OfferImporter
 * @see docs/modules/Offer.md — the seller offer feed
 */
final class OfferImports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.offer.pages.offer-imports';

    /**
     * **UNDER "Teklifler", NOT "Katalog".** This is the history of the OFFER
     * feed — a seller's own prices and stock — and the catalogue is the
     * platform's, not theirs. It shipped pointing at `nav.catalog`, a key that
     * does not exist, so the sidebar rendered the group as the literal string
     * `nav.catalog` and the page was effectively unfindable.
     */
    public static function getNavigationGroup(): string
    {
        return __('nav.offers');
    }

    public static function getNavigationLabel(): string
    {
        return __('offer.imports.title');
    }

    public function getTitle(): string
    {
        return __('offer.imports.title');
    }

    public function getSubheading(): string
    {
        return __('offer.imports.subheading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->importQuery())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('file_name')
                    ->label(__('offer.imports.file'))
                    ->wrap(),

                TextColumn::make('created_at')
                    ->label(__('offer.imports.uploaded_at'))
                    ->dateTime('d.m.Y H:i'),

                TextColumn::make('total_rows')
                    ->label(__('offer.imports.rows'))
                    ->numeric(),

                TextColumn::make('successful_rows')
                    ->label(__('offer.imports.succeeded'))
                    ->numeric()
                    ->color('success'),

                TextColumn::make('failed_rows_count')
                    ->label(__('offer.imports.failed'))
                    ->numeric()
                    ->color(fn (?int $state): string => ($state ?? 0) > 0 ? 'danger' : 'gray'),

                TextColumn::make('completed_at')
                    ->label(__('offer.imports.status'))
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => $state === null
                        ? __('offer.imports.running')
                        : __('offer.imports.done'))
                    ->color(fn (?int $state): string => $state === null ? 'warning' : 'success'),
            ])
            ->actions([
                Action::make('detail')
                    ->label(__('offer.imports.detail'))
                    ->icon('heroicon-o-eye')
                    ->visible(fn (Import $record): bool => $this->failureCount($record) > 0)
                    ->modalHeading(fn (Import $record): string => $record->file_name)
                    ->modalContent(fn (Import $record): View => view(
                        'filament.offer.pages.offer-import-failures',
                        [
                            'reasons' => $this->reasonBreakdown($record),
                            'rows' => $this->sampleFailedRows($record),
                            'total' => $this->failureCount($record),
                        ],
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('offer.imports.close')),

                Action::make('download')
                    ->label(__('offer.imports.download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Import $record): bool => $this->failureCount($record) > 0)
                    ->url(fn (Import $record): string => route(
                        'filament.imports.failed-rows.download',
                        ['import' => $record],
                    ), shouldOpenInNewTab: true),
            ])
            ->emptyStateHeading(__('offer.imports.empty'))
            ->emptyStateDescription(__('offer.imports.empty_hint'));
    }

    /**
     * The signed-in seller's offer-feed imports, newest first.
     *
     * **`withCount` RATHER THAN A LAZY RELATION.** Strict mode makes lazy loading
     * throw, and a count in a table column is the classic way to trip it — once
     * per row, on a page that exists to be scanned.
     *
     * @return Builder<Import>
     */
    private function importQuery(): Builder
    {
        /** @var Builder<Import> $query */
        $query = Import::query()
            ->whereBelongsTo(current_actor(), 'user')
            ->where('importer', OfferImporter::class)
            ->withCount('failedRows');

        return $query;
    }

    private function failureCount(Import $import): int
    {
        return (int) ($import->failed_rows_count ?? 0);
    }

    /**
     * How many rows failed for each distinct reason.
     *
     * The empty string is Filament's placeholder for an exception it did not
     * expect, so it is labelled rather than shown blank — a seller reading
     * "(sebep kaydedilmedi)" at least knows to ask, which "" does not tell them.
     *
     * @return array<int, array{reason: string, count: int}>
     */
    private function reasonBreakdown(Import $import): array
    {
        return DB::table('failed_import_rows')
            ->where('import_id', $import->getKey())
            ->selectRaw('coalesce(nullif(validation_error, \'\'), ?) as reason, count(*) as total', [
                __('offer.imports.unknown_reason'),
            ])
            ->groupBy('reason')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'reason' => (string) $row->reason,
                'count' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * A handful of real rows, so a reason stops being abstract.
     *
     * @return array<int, array{values: string, reason: string}>
     */
    private function sampleFailedRows(Import $import): array
    {
        return DB::table('failed_import_rows')
            ->where('import_id', $import->getKey())
            ->orderBy('id')
            ->limit(25)
            ->get(['data', 'validation_error'])
            ->map(function (object $row): array {
                /** @var array<string, mixed> $data */
                $data = json_decode((string) $row->data, true) ?: [];

                return [
                    'values' => collect($data)
                        ->map(fn (mixed $value, string $key): string => $key.': '.(string) $value)
                        ->implode('  ·  '),
                    'reason' => (string) ($row->validation_error ?? ''),
                ];
            })
            ->all();
    }
}
