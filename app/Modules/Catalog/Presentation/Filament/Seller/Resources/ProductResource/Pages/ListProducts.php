<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('catalog.product.open')),
        ];
    }
}
