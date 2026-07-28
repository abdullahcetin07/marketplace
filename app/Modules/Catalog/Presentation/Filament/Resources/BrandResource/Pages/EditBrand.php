<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\BrandResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\BrandResource;
use Filament\Resources\Pages\EditRecord;

final class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
