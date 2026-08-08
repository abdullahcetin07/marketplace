<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Seller\Resources\ReturnRequestResource\Pages;

use App\Modules\Order\Presentation\Filament\Seller\Resources\ReturnRequestResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The inbox. No header action: a seller does not create a buyer's return.
 */
final class ListReturnRequests extends ListRecords
{
    protected static string $resource = ReturnRequestResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
