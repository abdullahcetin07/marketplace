<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource\Pages;

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
        ];
    }
}
