<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Presentation\Filament\Resources\CargoCompanyResource\Pages;

use App\Modules\Shipping\Presentation\Filament\Resources\CargoCompanyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListCargoCompanies extends ListRecords
{
    protected static string $resource = CargoCompanyResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
