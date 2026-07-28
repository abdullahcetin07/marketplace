<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\AttributeResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\AttributeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListAttributes extends ListRecords
{
    protected static string $resource = AttributeResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
