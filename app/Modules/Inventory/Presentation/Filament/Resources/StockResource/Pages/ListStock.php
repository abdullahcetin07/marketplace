<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Filament\Resources\StockResource\Pages;

use App\Modules\Inventory\Presentation\Filament\Resources\StockResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Stock across every seller. No header action — there is nothing an operator
 * creates here (§7).
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
