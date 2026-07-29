<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\StaffResource\Pages;

use App\Modules\Identity\Presentation\Filament\Resources\StaffResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * A colleague's profile, roles and login history. Changes are the Edit page's.
 */
final class ViewStaff extends ViewRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
