<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Pages;

use App\Modules\Catalog\Presentation\Filament\Imports\ProductImporter;
use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Katalog İçe Aktarma" — the admin's Excel/CSV upload (ADR-074).
 *
 * **IT LIVES IN THE MODULE, NOT IN `app/Filament` — a DEVIATION from the work
 * order**, which said to put it under `app/Filament/Admin/Pages` for Filament's
 * auto-discovery. `LayeringTest` forbids it: `App\Modules\Catalog` may only be used
 * from Catalog itself, its database/tests namespaces, and `App\Providers\Filament`
 * — the composition root. Auto-discovery would have placed an admin surface OUTSIDE
 * the module whose actions it drives, which is the boundary every other Filament
 * resource here respects. Registered explicitly in `AdminPanelProvider->pages()`
 * instead, exactly as every module's resources are.
 *
 * **A PAGE RATHER THAN A SECOND PRODUCT LIST, AND THE MODERATION QUEUE SAID SO.**
 * The work order offered three hosts: the existing `ProductModerationResource`, a
 * new minimal `ProductResource`, or a page. The first is out by its own design —
 * `ListProductModeration` carries the note "no header actions: nothing is created
 * from the queue" — and the second would put a second product table in the
 * navigation beside a moderation queue that already lists products, which is one
 * list too many for an admin to reason about. A page has no table to confuse
 * anybody with.
 *
 * **THE UPLOAD IS QUEUED AND IS INERT WITHOUT A WORKER.** The same operational
 * truth the scheduler carries for the sweeps: with no `queue:work`, an admin
 * uploads a file, sees a cheerful notification, and nothing whatsoever happens.
 * The page says so on screen rather than leaving it in a run-book.
 *
 * GATED ON `catalog.products.moderate`, not on a bespoke import ability: the
 * import PUBLISHES products, which is precisely what that permission means. A
 * separate ability would be a second answer to one question.
 *
 * @see App\Modules\Catalog\Presentation\Filament\Imports\ProductImporter
 * @see docs/modules/Catalog.md — bulk import
 */
final class ImportCatalog extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 9;

    /*
    | THE BLADE LIVES IN `resources/views`, not in the module. No module here
    | registers a view namespace, and inventing one for a single page would be a
    | new convention for a screen. The layering rule is about PHP namespaces; a
    | template path is not one.
    */
    protected static string $view = 'filament.catalog.pages.import-catalog';

    public static function getNavigationGroup(): string
    {
        return __('nav.catalogue');
    }

    public static function getNavigationLabel(): string
    {
        return __('catalog.import.title');
    }

    public function getTitle(): string
    {
        return __('catalog.import.title');
    }

    public function getSubheading(): string
    {
        return __('catalog.import.subheading');
    }

    /**
     * Only somebody who may publish a product may import a thousand of them.
     *
     * **THE PERMISSION DIRECTLY, NOT `can('moderate', Product::class)`.**
     * `ProductPolicy::moderate()` is typed `(User, Product)`, so a class-string
     * check would hand it the string `"App\\...\\Product"` where a model is
     * declared and blow up with a TypeError on a page nobody could then open.
     * Class-level abilities on that policy are `viewAny`-shaped; publishing is
     * not one of them.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('catalog.products.moderate') === true;
    }

    /**
     * One filled-in row, so the syntax is shown rather than described.
     *
     * @return array<string, string>
     */
    public static function exampleRow(): array
    {
        return [
            'baslik' => 'Pamuklu Bisiklet Yaka Tişört',
            'kategori_yolu' => 'Erkek > Giyim > Tişört',
            'marka' => 'Örnek Marka',
            'gtin' => '08691234567890',
            'aciklama' => '%100 pamuk, regular fit.',
            'kdv' => '%20',
            'gorsel_url' => 'https://ornek.com/1.jpg | https://ornek.com/2.jpg',
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(ProductImporter::class)
                ->label(__('catalog.import.action'))
                ->icon('heroicon-o-arrow-up-tray'),
            /*
                | THE EXAMPLE FILE COMES FROM THE COLUMNS in this Filament version
                | — `ImportColumn::example()`, not an action-level
                | `downloadableExampleFileContent()`, which does not exist here.
                | The upload modal's own "download example" button is generated
                | from them. @see `ProductImporter::getColumns()`.
                */

            Action::make('template')
                ->label(__('catalog.import.download_template'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => self::templateDownload()),
        ];
    }

    /**
     * The same thing as a plain CSV, for the admin who wants it without opening
     * the upload dialog.
     *
     * **UTF-8 BOM, DELIBERATELY.** Excel on Windows opens a BOM-less UTF-8 CSV in
     * the system codepage, which turns "Tişört" into "TiÅŸÃ¶rt" in the header row
     * of a template whose whole job is to be copied.
     */
    private static function templateDownload(): StreamedResponse
    {
        $columns = ['baslik', 'kategori_yolu', 'marka', 'gtin', 'aciklama', 'kdv', 'gorsel_url'];
        $example = self::exampleRow();

        return Response::streamDownload(function () use ($columns, $example): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);
            fputcsv($handle, array_map(static fn (string $key): string => $example[$key], $columns));

            fclose($handle);
        }, 'katalog-sablonu.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
