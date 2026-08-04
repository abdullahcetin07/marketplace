<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\AttributeResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\AttributeResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateAttribute extends CreateRecord
{
    protected static string $resource = AttributeResource::class;

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return AttributeResource::createFromForm($data);
    }
}
