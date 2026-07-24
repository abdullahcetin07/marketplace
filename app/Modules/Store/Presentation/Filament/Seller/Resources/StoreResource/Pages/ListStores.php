<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Filament\Seller\Resources\StoreResource\Pages;

use App\Modules\Store\Presentation\Filament\Seller\Resources\StoreResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The seller store list. No "new" — a store is created only from an approved
 * Store Opening Request (ADR-028); the seller raises the request, not the store.
 */
final class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
