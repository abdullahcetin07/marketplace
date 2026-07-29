<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\StaffResource\Pages;

use App\Modules\Identity\Presentation\Filament\Resources\StaffResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * The staff listing — the one account list on this panel with a "New" button.
 *
 * Sellers and customers self-register and are never conjured from the panel;
 * staff are provisioned internally, which is exactly what this area is for.
 */
final class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('users.staff.action.create')),
        ];
    }
}
