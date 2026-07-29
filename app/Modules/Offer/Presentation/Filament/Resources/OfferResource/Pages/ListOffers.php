<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Filament\Resources\OfferResource\Pages;

use App\Modules\Offer\Presentation\Filament\Resources\OfferResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Every offer on the platform. No header action: an admin does not list
 * products for sale, and there is nothing here to create.
 */
final class ListOffers extends ListRecords
{
    protected static string $resource = OfferResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
