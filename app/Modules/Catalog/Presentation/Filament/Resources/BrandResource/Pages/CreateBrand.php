<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\BrandResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\BrandResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateBrand extends CreateRecord
{
    protected static string $resource = BrandResource::class;

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }

    /**
     * Through the action, which owns slug uniqueness and dispatches
     * `BrandCreated`.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return BrandResource::createFromForm($data);
    }
}
