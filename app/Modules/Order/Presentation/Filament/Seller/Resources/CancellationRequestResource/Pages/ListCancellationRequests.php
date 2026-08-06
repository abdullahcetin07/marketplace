<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Seller\Resources\CancellationRequestResource\Pages;

use App\Modules\Order\Presentation\Filament\Seller\Resources\CancellationRequestResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The inbox. No header action: a seller does not create a buyer's request.
 */
final class ListCancellationRequests extends ListRecords
{
    protected static string $resource = CancellationRequestResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
