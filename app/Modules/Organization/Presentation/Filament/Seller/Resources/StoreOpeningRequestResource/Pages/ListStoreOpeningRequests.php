<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource\Pages;

use App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * The seller's store-opening requests, across every company they belong to.
 */
final class ListStoreOpeningRequests extends ListRecords
{
    protected static string $resource = StoreOpeningRequestResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('organization.store_request.action.create')),
        ];
    }
}
