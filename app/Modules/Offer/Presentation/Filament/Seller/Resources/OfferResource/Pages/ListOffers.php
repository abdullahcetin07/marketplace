<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource\Pages;

use App\Modules\Offer\Application\Import\OfferImportChunk;
use App\Modules\Offer\Presentation\Filament\Seller\Imports\OfferImporter;
use App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * "Tekliflerim" — the seller's listings.
 *
 * The header action is the catalog-first entry point (§4): a seller sells
 * something that already exists in the shared catalog, so the flow starts with
 * finding it, not with a blank form.
 */
final class ListOffers extends ListRecords
{
    protected static string $resource = OfferResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('offer.action.create')),

            /*
            | THE SPREADSHEET DOOR (ADR-076). Beside "add an offer" because it is
            | the same act at a different scale — one SKU by hand, or four thousand
            | from the file the seller's stock system already produces.
            |
            | **THE CHUNK JOB IS CAPPED** (`$tries` + `$backoff`): Filament's stock
            | job retries without limit, which cost the catalogue import 29,074
            | attempts overnight for five bad rows (ADR-075).
            */
            Actions\ImportAction::make()
                ->importer(OfferImporter::class)
                ->job(OfferImportChunk::class)
                ->label(__('offer.feed.import'))
                ->icon('heroicon-o-arrow-up-tray'),
        ];
    }
}
