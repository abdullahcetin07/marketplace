<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\BrandResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\BrandResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListBrands extends ListRecords
{
    protected static string $resource = BrandResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
