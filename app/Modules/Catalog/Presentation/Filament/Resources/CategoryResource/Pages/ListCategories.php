<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
