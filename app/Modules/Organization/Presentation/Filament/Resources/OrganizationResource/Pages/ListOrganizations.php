<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource\Pages;

use App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The organization review queue. No "new" action — organizations are
 * seller-registered, never created from the admin panel.
 */
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
