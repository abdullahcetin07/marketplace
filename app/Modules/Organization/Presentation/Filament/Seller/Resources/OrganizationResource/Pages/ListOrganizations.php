<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages;

use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * The seller's companies. A seller may own or belong to several (ADR-030), so
 * "new" stays available even once one exists.
 */
final class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('organization.action.create')),
        ];
    }
}
