<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Resources\StoreOpeningRequestResource\Pages;

use App\Modules\Organization\Presentation\Filament\Resources\StoreOpeningRequestResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The Store Opening Request queue. No "new" — requests come from sellers.
 */
final class ListStoreOpeningRequests extends ListRecords
{
    protected static string $resource = StoreOpeningRequestResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
