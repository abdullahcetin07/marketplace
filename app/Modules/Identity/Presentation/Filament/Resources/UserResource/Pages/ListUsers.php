<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\UserResource\Pages;

use App\Modules\Identity\Presentation\Filament\Resources\UserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The user listing.
 *
 * No "New user" header action on purpose: admins are created by
 * `marketplace:create-admin` only, and sellers and customers self-register. An
 * account is never conjured from this panel.
 */
final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
