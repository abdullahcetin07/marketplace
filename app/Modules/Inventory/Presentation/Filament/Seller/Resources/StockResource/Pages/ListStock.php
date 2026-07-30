<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Filament\Seller\Resources\StockResource\Pages;

use App\Modules\Inventory\Presentation\Filament\Seller\Resources\StockResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The seller's stock. No header action: a pool exists because the seller listed
 * something, and the quantity is entered on the Offer form (ADR-048).
 */
final class ListStock extends ListRecords
{
    protected static string $resource = StockResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
