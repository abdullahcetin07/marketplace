<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages;

use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource;
use Filament\Resources\Pages\ListRecords;

final class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
