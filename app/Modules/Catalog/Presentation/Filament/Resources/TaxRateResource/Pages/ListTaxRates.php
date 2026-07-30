<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListTaxRates extends ListRecords
{
    protected static string $resource = TaxRateResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
